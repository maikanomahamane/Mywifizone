<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Network\CommandStatus;
use App\Domain\Network\CommandType;
use App\Http\Controllers\Controller;
use App\Models\HotspotProfile;
use App\Models\Router;
use App\Models\RouterCommand;
use App\Models\Site;
use App\Models\Subscription;
use App\Services\AuditLogger;
use App\Services\CommandQueue;
use App\Services\ConnectorManager;
use App\Services\PlanEntitlements;
use App\Services\RestRouterProvisioner;
use App\Services\RouterService;
use App\Services\VoucherService;
use App\Support\ApiException;
use App\Support\ApiResponse;
use App\Support\Rules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class RouterController extends Controller
{
    public function __construct(
        private readonly RouterService $routers,
        private readonly RestRouterProvisioner $provisioner,
        private readonly ConnectorManager $connectors,
        private readonly CommandQueue $queue,
        private readonly VoucherService $vouchers,
        private readonly PlanEntitlements $entitlements,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Router::class);

        $filters = $request->validate(['site_id' => ['nullable', 'uuid'], 'status' => ['nullable', Rule::in(['PENDING', 'ONLINE', 'OFFLINE'])]]);

        $page = Router::query()
            ->when($filters['site_id'] ?? null, fn ($q, $v) => $q->where('site_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderBy('name')
            ->paginate(Rules::perPage($request));

        return ApiResponse::paginated($page, fn (Router $r) => $r->toPayload());
    }

    public function show(string $id): JsonResponse
    {
        $router = Router::findOrFail($id);
        Gate::authorize('view', $router);

        return ApiResponse::ok($router->toPayload());
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Router::class);

        $data = $request->validate([
            'site_id' => ['required', 'uuid', Rules::ownedBy(Site::class)],
            'name' => ['required', 'string', 'max:120'],
            'model' => ['nullable', 'string', 'max:80'],
            'hotspot_server' => ['nullable', 'string', 'regex:/^[A-Za-z0-9._-]{1,60}$/D'],
            'connection_mode' => ['sometimes', Rule::in(['AGENT', 'REST'])],
            // Connexion directe : adresse du routeur + identifiants ADMIN (utilisés une seule fois, jamais conservés).
            'host' => ['required_if:connection_mode,REST', 'nullable', 'string', 'max:253'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'username' => ['required_if:connection_mode,REST', 'nullable', 'string', 'max:64'],
            'password' => ['required_if:connection_mode,REST', 'nullable', 'string', 'max:255'],
            'verify_tls' => ['nullable', 'boolean'],
        ]);
        $rest = $data['connection_mode'] ?? 'AGENT';
        $connection = array_intersect_key($data, array_flip(['host', 'port', 'username', 'password', 'verify_tls']));
        $data = array_filter(array_diff_key($data, array_flip(['connection_mode', 'host', 'port', 'username', 'password', 'verify_tls'])), fn ($v) => $v !== null);

        if ($rest === 'REST') {
            return $this->storeRest($data, $connection);
        }

        [$router, $code] = DB::transaction(function () use ($data) {
            // Verrou sur l'abonnement : deux créations simultanées ne peuvent pas dépasser la limite du plan.
            Subscription::lockForUpdate()->first();
            $this->entitlements->assertCanAdd('max_routers', Router::count());

            return $this->routers->create($data);
        });

        $this->audit->log('router.created', null, $router, ['name' => $router->name]);

        return ApiResponse::ok($router->toPayload() + ['enrollment' => $this->enrollmentPayload($router, $code)], status: 201);
    }

    /**
     * Ajout par identifiants MikroTik : le routeur est créé puis connecté. Si la connexion échoue, rien n'est conservé
     * (le routeur créé est supprimé) et l'erreur dit précisément quoi corriger.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $c
     */
    private function storeRest(array $data, array $c): JsonResponse
    {
        $router = DB::transaction(function () use ($data) {
            Subscription::lockForUpdate()->first();
            $this->entitlements->assertCanAdd('max_routers', Router::count());

            return $this->routers->createRest($data);
        });

        try {
            $this->provisioner->connect(
                $router, (string) $c['host'], (int) ($c['port'] ?? 443), (string) $c['username'], (string) $c['password'],
                (bool) ($c['verify_tls'] ?? false), $data['hotspot_server'] ?? null,
            );
        } catch (\Throwable $e) {
            $router->delete();

            throw $e;
        }

        $this->audit->log('router.created', null, $router, ['name' => $router->name, 'mode' => 'REST', 'host' => $router->rest_host]);

        return ApiResponse::ok($router->refresh()->toPayload(), status: 201);
    }

    /** Teste la connexion directe avec le compte dédié enregistré (ne modifie rien sur le routeur). */
    public function testConnection(string $id): JsonResponse
    {
        $router = Router::findOrFail($id);
        Gate::authorize('update', $router);
        $this->assertRest($router);

        $health = $this->provisioner->test($router);

        return ApiResponse::ok(['ok' => true, 'health' => $health] + $router->refresh()->toPayload());
    }

    /** Reconnecte avec de nouveaux identifiants admin (mot de passe changé, nouvelle adresse) : le compte dédié est recréé. */
    public function reconnect(Request $request, string $id): JsonResponse
    {
        $router = Router::findOrFail($id);
        Gate::authorize('update', $router);
        $this->assertRest($router);

        $c = $request->validate([
            'host' => ['nullable', 'string', 'max:253'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'username' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'max:255'],
            'verify_tls' => ['nullable', 'boolean'],
        ]);

        $this->provisioner->connect(
            $router, (string) ($c['host'] ?? $router->rest_host), (int) ($c['port'] ?? $router->rest_port ?? 443), $c['username'], $c['password'],
            (bool) ($c['verify_tls'] ?? $router->rest_verify_tls), $router->hotspot_server,
        );
        $this->audit->log('router.reconnected', null, $router, ['host' => $router->rest_host]);

        return ApiResponse::ok($router->refresh()->toPayload());
    }

    private function assertRest(Router $router): void
    {
        if (! $router->isRest()) {
            throw new ApiException('not_direct_router', 'Ce routeur n\'est pas en connexion directe.', 409);
        }
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $router = Router::findOrFail($id);
        Gate::authorize('update', $router);

        $router->update($request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'model' => ['sometimes', 'nullable', 'string', 'max:80'],
            'hotspot_server' => ['sometimes', 'string', 'regex:/^[A-Za-z0-9._-]{1,60}$/D'],
        ]));

        $this->audit->log('router.updated', null, $router);

        return ApiResponse::ok($router->toPayload());
    }

    public function destroy(string $id): JsonResponse
    {
        $router = Router::findOrFail($id);
        Gate::authorize('delete', $router);

        $router->delete();
        $this->audit->log('router.deleted', null, $router, ['name' => $router->name]);

        return ApiResponse::ok(null);
    }

    /** Invalide le jeton de l'agent et émet un nouveau code d'enrôlement (à réutiliser sur l'agent). */
    public function rotateAgentToken(string $id): JsonResponse
    {
        $router = Router::findOrFail($id);
        Gate::authorize('update', $router);
        if ($router->isRest()) {
            throw new ApiException('not_agent_router', 'Ce routeur est en connexion directe (pas d\'agent).', 409);
        }

        $code = $this->routers->rotateAgentToken($router);
        $this->audit->log('router.agent_token_rotated', null, $router);

        return ApiResponse::ok($router->toPayload() + ['enrollment' => $this->enrollmentPayload($router, $code)]);
    }

    /**
     * Resynchronise le routeur : profils HotSpot, relevé de santé et de sessions, renvoi des vouchers dont l'envoi a échoué.
     * Les commandes sont mises en file : elles s'exécutent quand l'agent les récupère (202).
     */
    public function sync(Request $request, string $id): JsonResponse
    {
        $router = Router::findOrFail($id);
        Gate::authorize('update', $router);

        $connector = $this->connectors->for($router);
        $actorId = $request->user()?->getKey();
        $profiles = HotspotProfile::where('is_active', true)->get()->map(fn (HotspotProfile $p) => $p->toAgentPayload())->all();

        $queued = 0;
        if ($profiles !== []) {
            $connector->send($router, CommandType::SYNC_PROFILES, ['profiles' => $profiles], $actorId);
            $queued++;
        }
        foreach ([CommandType::FETCH_HEALTH, CommandType::FETCH_SESSIONS] as $type) {
            if (! $this->queue->findOpen($router, $type)) {
                $connector->send($router, $type, [], $actorId);
                $queued++;
            }
        }
        $resent = $this->vouchers->resyncFailed($router, $request->user());

        $this->audit->log('router.sync_requested', null, $router, ['commands' => $queued, 'vouchers_resent' => $resent]);

        return ApiResponse::ok(['commands_queued' => $queued, 'vouchers_resent' => $resent], status: 202);
    }

    public function commands(Request $request, string $id): JsonResponse
    {
        $router = Router::findOrFail($id);
        Gate::authorize('update', $router); // diagnostic réservé à qui gère le routeur

        $filters = $request->validate(['status' => ['nullable', Rule::in(CommandStatus::ALL)]]);

        $page = RouterCommand::where('router_id', $router->getKey())
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(Rules::perPage($request));

        return ApiResponse::paginated($page, fn (RouterCommand $c) => $c->toPayload());
    }

    /** Demande un relevé immédiat des sessions (au lieu d'attendre le prochain signal de l'agent). */
    public function refreshSessions(Request $request, string $id): JsonResponse
    {
        $router = Router::findOrFail($id);
        Gate::authorize('view', $router);

        $command = $this->queue->findOpen($router, CommandType::FETCH_SESSIONS)
            ?? $this->connectors->for($router)->send($router, CommandType::FETCH_SESSIONS, [], $request->user()?->getKey());

        return ApiResponse::ok($command->toPayload(), status: 202);
    }

    /** @return array<string, mixed> */
    private function enrollmentPayload(Router $router, string $code): array
    {
        return [
            'code' => $code,                                             // affiché une seule fois
            'expires_at' => $router->enrollment_expires_at?->toIso8601String(),
        ];
    }
}
