<?php

namespace App\Services;

use App\Infrastructure\RouterOs\RouterOsException;
use App\Models\Router;
use App\Support\ApiException;
use Illuminate\Support\Str;

/**
 * Connexion d'un routeur par les identifiants ADMIN du propriétaire. L'admin sert une seule fois : l'application crée sur le
 * routeur un groupe et un utilisateur dédiés aux droits limités (`wifizone`), vérifie qu'ils fonctionnent, ne conserve QUE ce
 * compte (mot de passe aléatoire, chiffré en base) et oublie l'admin. Le mot de passe admin n'est ni stocké ni journalisé.
 */
final class RestRouterProvisioner
{
    public function __construct(
        private readonly RestClientFactory $clients,
        private readonly RouterTelemetry $telemetry,
    ) {}

    /** @throws ApiException */
    public function connect(Router $router, string $host, int $port, string $adminUser, string $adminPassword, bool $verifyTls, ?string $hotspotServer = null): void
    {
        $host = strtolower(trim($host));
        $user = (string) config('network.rest.dedicated_user');
        $group = (string) config('network.rest.dedicated_group');
        $policy = (string) config('network.rest.group_policy');
        $dedicatedPassword = Str::random(32);

        try {
            $admin = $this->clients->make($host, $port, $adminUser, $adminPassword, $verifyTls);
            $admin->resource();                                   // identifiants et joignabilité

            $servers = $admin->hotspotServers();
            $server = $this->pickServer($servers, $hotspotServer);

            // Groupe puis utilisateur dédiés : créés, ou remis à l'état attendu (mot de passe renouvelé).
            $existingGroup = $this->byName($admin->systemGroups(), $group);
            $existingGroup
                ? $admin->updateSystemGroup((string) $existingGroup['.id'], ['policy' => $policy])
                : $admin->createSystemGroup(['name' => $group, 'policy' => $policy]);

            $existingUser = $this->byName($admin->systemUsers(), $user);
            $existingUser
                ? $admin->updateSystemUser((string) $existingUser['.id'], ['password' => $dedicatedPassword, 'group' => $group])
                : $admin->createSystemUser(['name' => $user, 'password' => $dedicatedPassword, 'group' => $group]);

            // Vérification avec le compte dédié : on ne garde que ce qui fonctionne réellement.
            $dedicated = $this->clients->make($host, $port, $user, $dedicatedPassword, $verifyTls);
            $resource = $dedicated->resource();
            $dedicated->hotspotUsers();
        } catch (RouterOsException $e) {
            throw $this->toApiException($e);
        }

        $router->forceFill([
            'connection_mode' => 'REST',
            'rest_host' => $host,
            'rest_port' => $port,
            'rest_verify_tls' => $verifyTls,
            'rest_username' => $user,
            'rest_password' => $dedicatedPassword,
            'rest_error' => null,
            'rest_checked_at' => now(),
            'hotspot_server' => $server,
            'agent_token_hash' => null,
            'enrollment_hash' => null,
            'enrollment_expires_at' => null,
        ])->save();

        $this->telemetry->markSeen($router);
        $this->telemetry->ingestHealth($router, RouterOsCommandExecutor::health($resource));
    }

    /** Teste le compte dédié déjà enregistré (sans rien modifier). @return array<string, mixed> santé */
    public function test(Router $router): array
    {
        try {
            $client = $this->clients->forRouter($router);
            $resource = $client->resource();
            $client->hotspotUsers();
        } catch (RouterOsException $e) {
            $router->forceFill(['rest_error' => mb_substr($e->getMessage(), 0, 255), 'rest_checked_at' => now()])->save();

            throw $this->toApiException($e);
        }

        $this->telemetry->markSeen($router);
        $router->forceFill(['rest_error' => null, 'rest_checked_at' => now()])->save();
        $health = RouterOsCommandExecutor::health($resource);
        $this->telemetry->ingestHealth($router, $health);

        return $health;
    }

    /**
     * @param  array<int, array<string, mixed>>  $servers
     *
     * @throws ApiException
     */
    private function pickServer(array $servers, ?string $wanted): string
    {
        if ($servers === []) {
            throw new ApiException('hotspot_not_configured', 'Aucun HotSpot n\'est configuré sur ce routeur : lancez « /ip hotspot setup » sur le routeur, puis réessayez.', 422);
        }
        if ($wanted === null || $wanted === '') {
            return (string) ($servers[0]['name'] ?? 'hotspot1');
        }
        foreach ($servers as $s) {
            if (($s['name'] ?? null) === $wanted) {
                return $wanted;
            }
        }

        throw new ApiException('hotspot_not_found', 'Ce serveur HotSpot n\'existe pas sur le routeur.', 422, ['available' => array_values(array_map(fn ($s) => (string) ($s['name'] ?? ''), $servers))]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function byName(array $rows, string $name): ?array
    {
        foreach ($rows as $row) {
            if (($row['name'] ?? null) === $name) {
                return $row;
            }
        }

        return null;
    }

    public function toApiException(RouterOsException $e): ApiException
    {
        $code = match ($e->kind) {
            'auth' => 'router_auth_failed',
            'forbidden' => 'router_rights_insufficient',
            'target' => 'router_target_forbidden',
            'unreachable' => 'router_unreachable',
            'timeout' => 'router_timeout',
            'tls' => 'router_tls_failed',
            default => 'router_api_error',
        };

        return new ApiException($code, $e->getMessage(), 422);
    }
}
