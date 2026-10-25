<?php

namespace App\Infrastructure\RouterOs;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Client de l'API REST de RouterOS 7 (https://help.mikrotik.com/docs/display/ROS/REST+API) : PUT = créer, PATCH = modifier,
 * DELETE = supprimer, GET = lire. HTTPS uniquement (les identifiants ne circulent jamais en clair).
 * NON VALIDÉ sur un routeur réel : tests uniquement contre des réponses simulées.
 */
final class RouterOsRestClient
{
    /** @param string|null $pinnedIp IP résolue et validée : la connexion s'y fait, pas à une seconde résolution DNS (anti « DNS rebinding »). */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly bool $verifyTls = false,
        private readonly ?string $pinnedIp = null,
    ) {}

    /** @return array<string, mixed> */
    public function resource(): array
    {
        return $this->call('GET', '/system/resource');
    }

    /** @return array<int, array<string, mixed>> */
    public function hotspotServers(): array
    {
        return $this->call('GET', '/ip/hotspot');
    }

    /** @return array<int, array<string, mixed>> */
    public function activeSessions(): array
    {
        return $this->call('GET', '/ip/hotspot/active');
    }

    /** @return array<int, array<string, mixed>> */
    public function hotspotUsers(): array
    {
        return $this->call('GET', '/ip/hotspot/user');
    }

    /** @return array<int, array<string, mixed>> */
    public function profiles(): array
    {
        return $this->call('GET', '/ip/hotspot/user/profile');
    }

    /** @param array<string, string> $fields */
    public function createProfile(array $fields): array
    {
        return $this->call('PUT', '/ip/hotspot/user/profile', $fields);
    }

    /** @param array<string, string> $fields */
    public function updateProfile(string $id, array $fields): array
    {
        return $this->call('PATCH', '/ip/hotspot/user/profile/'.rawurlencode($id), $fields);
    }

    /** @param array<string, string> $fields */
    public function createUser(array $fields): array
    {
        return $this->call('PUT', '/ip/hotspot/user', $fields);
    }

    /** @param array<string, string> $fields */
    public function updateUser(string $id, array $fields): array
    {
        return $this->call('PATCH', '/ip/hotspot/user/'.rawurlencode($id), $fields);
    }

    public function removeUser(string $id): array
    {
        return $this->call('DELETE', '/ip/hotspot/user/'.rawurlencode($id));
    }

    public function removeActive(string $id): array
    {
        return $this->call('DELETE', '/ip/hotspot/active/'.rawurlencode($id));
    }

    // --- Comptes du routeur (provisionnement du compte dédié) ---

    /** @return array<int, array<string, mixed>> */
    public function systemGroups(): array
    {
        return $this->call('GET', '/user/group');
    }

    /** @param array<string, string> $fields */
    public function createSystemGroup(array $fields): array
    {
        return $this->call('PUT', '/user/group', $fields);
    }

    /** @param array<string, string> $fields */
    public function updateSystemGroup(string $id, array $fields): array
    {
        return $this->call('PATCH', '/user/group/'.rawurlencode($id), $fields);
    }

    /** @return array<int, array<string, mixed>> */
    public function systemUsers(): array
    {
        return $this->call('GET', '/user');
    }

    /** @param array<string, string> $fields */
    public function createSystemUser(array $fields): array
    {
        return $this->call('PUT', '/user', $fields);
    }

    /** @param array<string, string> $fields */
    public function updateSystemUser(string $id, array $fields): array
    {
        return $this->call('PATCH', '/user/'.rawurlencode($id), $fields);
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<mixed>
     */
    private function call(string $method, string $path, ?array $body = null): array
    {
        $options = ['verify' => $this->verifyTls, 'allow_redirects' => false];
        if ($this->pinnedIp !== null) {
            $options['curl'] = [CURLOPT_RESOLVE => ["{$this->host}:{$this->port}:{$this->pinnedIp}"]];
        }

        try {
            $response = Http::withBasicAuth($this->username, $this->password)
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('network.rest.timeout_seconds'))
                ->connectTimeout((int) config('network.rest.connect_timeout_seconds'))
                ->withOptions($options)
                ->send($method, "https://{$this->host}:{$this->port}/rest{$path}", $body === null ? [] : ['json' => $body]);
        } catch (ConnectionException $e) {
            throw $this->connectionError($e);
        }

        return $this->decode($response, $method, $path);
    }

    private function connectionError(ConnectionException $e): RouterOsException
    {
        $m = $e->getMessage();

        return match (true) {
            str_contains($m, 'cURL error 60'), str_contains($m, 'cURL error 51'), str_contains($m, 'cURL error 35'), str_contains($m, 'cURL error 58'),
            str_contains($m, 'cURL error 59') => new RouterOsException('tls', 'Certificat HTTPS du routeur non valide ou négociation TLS impossible.'),
            str_contains($m, 'cURL error 28') => new RouterOsException('timeout', 'Le routeur ne répond pas (délai dépassé).'),
            default => new RouterOsException('unreachable', 'Routeur injoignable depuis le serveur (adresse, port, pare-feu ?).'),
        };
    }

    /** @return array<mixed> */
    private function decode(Response $response, string $method, string $path): array
    {
        $status = $response->status();

        if ($status === 401) {
            throw new RouterOsException('auth', 'Identifiant ou mot de passe refusé par le routeur.', 401);
        }
        if ($status === 403) {
            throw new RouterOsException('forbidden', 'Ce compte n\'a pas les droits nécessaires sur le routeur.', 403);
        }
        if ($status === 404) {
            throw new RouterOsException('not_found', "Ressource RouterOS introuvable ($method $path).", 404);
        }
        if ($status < 200 || $status >= 300) {
            $detail = $response->json('detail') ?? $response->json('message');
            $detail = is_string($detail) ? mb_substr($detail, 0, 160) : '';

            throw new RouterOsException('http', "RouterOS a refusé $method $path ($status) $detail", $status);
        }

        $body = $response->body();
        if ($body === '') {
            return [];
        }
        $json = json_decode($body, true);
        if (! is_array($json)) {
            throw new RouterOsException('invalid', 'Réponse du routeur illisible (est-ce bien l\'API REST de RouterOS 7 ?).', $status);
        }

        return $json;
    }
}
