<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Network\CommandType;
use App\Domain\Network\RouterOsValue;
use App\Http\Controllers\Controller;
use App\Models\Router;
use App\Models\RouterCommand;
use App\Services\CommandQueue;
use App\Services\CommandResults;
use App\Services\RouterService;
use App\Services\RouterTelemetry;
use App\Support\ApiException;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * API de l'agent installé près du routeur (connexion SORTANTE : fonctionne derrière le CGNAT, sans IP publique).
 * Tout ce que l'agent envoie est validé strictement : c'est une entrée non fiable (jeton potentiellement volé).
 */
class AgentController extends Controller
{
    private const MAX_SESSIONS = 1000;

    public function __construct(
        private readonly RouterService $routers,
        private readonly RouterTelemetry $telemetry,
        private readonly CommandQueue $queue,
        private readonly CommandResults $results,
    ) {}

    /** Échange un code d'enrôlement (usage unique, 24 h) contre le jeton de l'agent. Non authentifié, limité par IP. */
    public function enroll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enrollment_code' => ['required', 'string', 'max:40'],
            'agent_version' => ['nullable', 'string', 'max:40'],
            'routeros_version' => ['nullable', 'string', 'max:40'],
            'model' => ['nullable', 'string', 'max:80'],
        ]);

        $enrolled = $this->routers->enroll($data['enrollment_code'], $data);

        // Même réponse pour code inconnu, expiré ou déjà utilisé.
        if ($enrolled === null) {
            throw new ApiException('invalid_enrollment_code', 'Code d\'enrôlement invalide ou expiré.', 422);
        }

        [$router, $token] = $enrolled;

        return ApiResponse::ok([
            'router_id' => $router->getKey(),
            'agent_token' => $token,                       // affiché une seule fois, à stocker sur le routeur
            'hotspot_server' => $router->hotspot_server,
            'poll_interval' => (int) config('network.poll_interval'),
            'heartbeat_interval' => (int) config('network.heartbeat_interval'),
        ], status: 201);
    }

    /** Signal de vie + santé + sessions actives + codes épuisés (tout est optionnel). */
    public function heartbeat(Request $request): JsonResponse
    {
        $router = $this->router($request);
        $data = $this->validateTelemetry($request->all(), required: false);

        $this->telemetry->markSeen($router);
        if (isset($data['health'])) {
            $this->telemetry->ingestHealth($router, $data['health']);
        }
        if (isset($data['sessions'])) {
            $this->telemetry->ingestSessions($router, $data['sessions'], $data['finished_codes'] ?? []);
        }

        return ApiResponse::ok(['server_time' => now()->toIso8601String()]);
    }

    /** Remet les commandes à exécuter (bail de 60 s : sans résultat, elles reviennent). */
    public function poll(Request $request): JsonResponse
    {
        $router = $this->router($request);
        $this->telemetry->markSeen($router);

        $commands = $this->queue->lease($router, (int) config('network.poll_batch'));

        return ApiResponse::ok($commands->map(fn (RouterCommand $c) => $c->toAgentPayload())->values()->all());
    }

    /**
     * Résultat d'une commande : { success: true, result: {...} } ou { success: false, error: "...", retryable?: bool }.
     * Idempotent : un résultat rejoué pour une commande déjà terminée est accepté sans effet.
     */
    public function result(Request $request, string $id): JsonResponse
    {
        $router = $this->router($request);

        // Sous scope tenant ET limité au routeur du jeton : un agent ne peut pas répondre pour un autre routeur.
        $command = RouterCommand::where('router_id', $router->getKey())->findOrFail($id);

        $body = $request->validate([
            'success' => ['required', 'boolean'],
            'error' => ['nullable', 'string', 'max:1000'],
            'retryable' => ['nullable', 'boolean'],
            'result' => ['nullable', 'array'],
        ]);

        if ($body['success']) {
            $result = $this->validateResult($command->type, (array) ($body['result'] ?? []));
            $done = $this->queue->complete($command, $result);
        } else {
            $done = $this->queue->fail($command, (string) ($body['error'] ?? 'Échec signalé par l\'agent.'), (bool) ($body['retryable'] ?? true));
        }

        if ($done === null) {
            return ApiResponse::ok(['status' => $command->refresh()->status, 'duplicate' => true]);
        }

        $this->results->apply($done);
        $this->telemetry->markSeen($router);

        return ApiResponse::ok(['status' => $done->status, 'duplicate' => false]);
    }

    private function router(Request $request): Router
    {
        /** @var Router $router */
        $router = $request->attributes->get('router');

        // Relu sous scope tenant : valeurs fraîches et cohérence avec le contexte posé par le middleware.
        return Router::findOrFail($router->getKey());
    }

    /**
     * Validation du résultat selon le type de commande. Contrat par type :
     *  - CREATE_VOUCHER : { created: [code...], failed: [{code, error}...] }
     *  - FETCH_SESSIONS : { sessions: [...], finished_codes: [code...] }
     *  - FETCH_HEALTH   : { health: {...} }
     *  - autres         : { } (rien d'exploité)
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function validateResult(string $type, array $result): array
    {
        return match ($type) {
            CommandType::CREATE_VOUCHER => $this->validateCreateResult($result),
            CommandType::FETCH_SESSIONS => $this->validateTelemetry($result, required: true, only: ['sessions', 'finished_codes']),
            CommandType::FETCH_HEALTH => $this->validateTelemetry($result, required: true, only: ['health']),
            default => [],
        };
    }

    /** @param array<string, mixed> $result @return array<string, mixed> */
    private function validateCreateResult(array $result): array
    {
        $v = Validator::make($result, [
            'created' => ['present', 'array', 'max:'.(int) config('network.voucher_chunk')],
            'created.*' => ['string', function (string $a, mixed $value, \Closure $fail) {
                if (! RouterOsValue::isValidCode($value)) {
                    $fail('Code invalide.');
                }
            }],
            'failed' => ['sometimes', 'array', 'max:'.(int) config('network.voucher_chunk')],
            'failed.*.code' => ['required_with:failed', 'string', 'max:64'],
            'failed.*.error' => ['nullable', 'string', 'max:200'],
        ]);

        return $v->validate();
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<int, string>|null  $only  clés attendues (sinon toutes)
     * @return array<string, mixed>
     */
    private function validateTelemetry(array $input, bool $required, ?array $only = null): array
    {
        $rules = [
            'health' => ['sometimes', 'array'],
            'health.board_name' => ['nullable', 'string', 'max:80'],
            'health.version' => ['nullable', 'string', 'max:40'],
            'health.agent_version' => ['nullable', 'string', 'max:40'],
            'health.cpu_load' => ['nullable', 'integer', 'min:0', 'max:100'],
            'health.free_memory' => ['nullable', 'integer', 'min:0'],
            'health.total_memory' => ['nullable', 'integer', 'min:0'],
            'health.uptime_seconds' => ['nullable', 'integer', 'min:0'],
            'sessions' => ['sometimes', 'array', 'max:'.self::MAX_SESSIONS],
            'sessions.*.id' => ['required', 'string', 'max:40'],
            'sessions.*.username' => ['required', 'string', 'max:64'],
            'sessions.*.address' => ['nullable', 'ip'],
            'sessions.*.mac' => ['nullable', 'regex:/^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/D'],
            'sessions.*.uptime_seconds' => ['nullable', 'integer', 'min:0'],
            'sessions.*.bytes_in' => ['nullable', 'integer', 'min:0'],
            'sessions.*.bytes_out' => ['nullable', 'integer', 'min:0'],
            'finished_codes' => ['sometimes', 'array', 'max:500'],
            'finished_codes.*' => ['string', 'max:64'],
        ];

        if ($only !== null) {
            $rules = array_filter($rules, fn ($k) => in_array(explode('.', $k)[0], $only, true), ARRAY_FILTER_USE_KEY);
            if ($required) {
                $rules[$only[0]] = array_merge(['present'], array_diff($rules[$only[0]], ['sometimes']));
            }
        }

        return Validator::make($input, $rules)->validate();
    }
}
