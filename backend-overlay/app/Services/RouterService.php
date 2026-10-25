<?php

namespace App\Services;

use App\Domain\Network\RouterOsValue;
use App\Domain\Network\RouterStatus;
use App\Models\NetworkEvent;
use App\Models\Router;
use App\Models\Tenant;
use App\Support\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Cycle de vie du lien agent ↔ cloud : création, enrôlement (code à usage unique), jeton, rotation. */
final class RouterService
{
    /**
     * Crée le routeur et son code d'enrôlement. Le code n'est retourné QU'UNE FOIS (seul son hachage est stocké).
     *
     * @param  array<string, mixed>  $data
     * @return array{0: Router, 1: string}
     */
    public function create(array $data): array
    {
        $router = new Router($data);
        $router->connection_mode = 'AGENT';
        $router->status = RouterStatus::PENDING;
        $code = $this->issueEnrollment($router);
        $router->save();

        NetworkEvent::record($router->tenant_id, $router->getKey(), 'ROUTER_CREATED', 'INFO', null, ['name' => $router->name]);

        return [$router, $code];
    }

    /** Routeur en connexion directe : pas de code d'enrôlement ; il devient utilisable après RestRouterProvisioner::connect(). */
    public function createRest(array $data): Router
    {
        $router = new Router($data);
        $router->connection_mode = 'REST';
        $router->status = RouterStatus::PENDING;
        $router->save();

        NetworkEvent::record($router->tenant_id, $router->getKey(), 'ROUTER_CREATED', 'INFO', null, ['name' => $router->name, 'mode' => 'REST']);

        return $router;
    }

    /**
     * Échange un code d'enrôlement contre un jeton d'agent (retourné une seule fois, stocké haché).
     * Hors contexte tenant : l'agent n'est pas encore authentifié. Retourne null si le code est inconnu, expiré ou déjà utilisé.
     *
     * @param  array<string, mixed>  $info
     * @return array{0: Router, 1: string}|null
     */
    public function enroll(string $code, array $info): ?array
    {
        $hash = hash('sha256', $this->normalizeCode($code));

        return DB::transaction(function () use ($hash, $info) {
            $router = Router::withoutGlobalScopes()
                ->where('enrollment_hash', $hash)
                ->where('enrollment_expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (! $router) {
                return null;
            }

            $tenant = Tenant::find($router->tenant_id);
            if (! $tenant || $tenant->isSuspended()) {
                return null;
            }

            $token = 'wza_'.Str::random(48);

            $router->forceFill([
                'agent_token_hash' => hash('sha256', $token),
                'agent_enrolled_at' => now(),
                'enrollment_hash' => null,       // usage unique
                'enrollment_expires_at' => null,
                'model' => $router->model ?? (isset($info['model']) ? mb_substr((string) $info['model'], 0, 80) : null),
                'routeros_version' => isset($info['routeros_version']) ? mb_substr((string) $info['routeros_version'], 0, 40) : $router->routeros_version,
            ])->save();

            NetworkEvent::record($router->tenant_id, $router->getKey(), 'AGENT_ENROLLED', 'INFO', null, [
                'agent_version' => isset($info['agent_version']) ? mb_substr((string) $info['agent_version'], 0, 40) : null,
            ]);

            return [$router, $token];
        });
    }

    /**
     * Rotation : l'ancien jeton cesse immédiatement de fonctionner et un nouveau code d'enrôlement est émis.
     * À utiliser en cas de doute sur la fuite d'un jeton.
     */
    public function rotateAgentToken(Router $router): string
    {
        $router->agent_token_hash = null;
        $router->agent_enrolled_at = null;
        $code = $this->issueEnrollment($router);
        $router->save();

        NetworkEvent::record($router->tenant_id, $router->getKey(), 'AGENT_TOKEN_ROTATED', 'WARNING');

        return $code;
    }

    private function issueEnrollment(Router $router): string
    {
        $raw = '';
        for ($i = 0; $i < 20; $i++) {
            $raw .= RouterOsValue::CODE_ALPHABET[random_int(0, strlen(RouterOsValue::CODE_ALPHABET) - 1)];
        }

        $router->enrollment_hash = hash('sha256', $raw);
        $router->enrollment_expires_at = now()->addMinutes((int) config('network.enrollment_ttl_minutes'));

        return 'WZ-'.implode('-', str_split($raw, 5));
    }

    /** Tolère tirets, espaces, minuscules et le préfixe « WZ- » (retiré seulement si la longueur correspond : « WZ » peut débuter le code brut). */
    private function normalizeCode(string $code): string
    {
        $code = strtoupper(preg_replace('/[\s\-]+/', '', $code) ?? '');

        return strlen($code) === 22 && str_starts_with($code, 'WZ') ? substr($code, 2) : $code;
    }

    /** @throws ApiException */
    public function assertEnrolled(Router $router): void
    {
        if (! $router->isEnrolled()) {
            throw new ApiException('router_not_enrolled', $router->isRest() ? 'Le routeur n\'est pas encore connecté.' : 'Le routeur n\'est pas encore enrôlé.', 409);
        }
    }
}
