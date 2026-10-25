<?php

namespace Tests\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Simulateur en mémoire de l'API REST de RouterOS 7 (d'après la documentation MikroTik), branché sur Http::fake.
 * Garde l'état (comptes, groupes, utilisateurs HotSpot, profils, sessions) et journalise les requêtes reçues.
 */
final class FakeRouterOs
{
    public array $systemUsers;      // name => ['id','password','group']

    public array $groups = [];

    public array $hotspotUsers = [];

    public array $profiles = [];

    public array $active = [];

    public array $servers = [['.id' => '*1', 'name' => 'hotspot1']];

    public array $log = [];          // [method, path, user, body]

    public bool $down = false;

    private int $seq = 16;

    public function __construct(public string $host = '41.203.1.10', public int $port = 443, string $adminPassword = 'admin-secret')
    {
        $this->systemUsers = ['admin' => ['id' => '*1', 'password' => $adminPassword, 'group' => 'full']];
    }

    public function install(): self
    {
        Http::fake(function (Request $request) {
            if ($this->down) {
                throw new ConnectionException('cURL error 7: Failed to connect');
            }
            if (! str_starts_with($request->url(), $this->prefix())) {
                throw new ConnectionException('cURL error 6: Could not resolve host');
            }

            return $this->handle($request);
        });

        return $this;
    }

    /** Guzzle retire le port par défaut (443) de l'URL : on compare avec la forme normalisée. */
    private function prefix(): string
    {
        return 'https://'.$this->host.($this->port === 443 ? '' : ':'.$this->port).'/rest';
    }

    private function handle(Request $request)
    {
        $path = substr($request->url(), strlen($this->prefix()));
        $method = $request->method();
        $body = $request->data();
        [$user, $pass] = explode(':', base64_decode(substr((string) $request->header('Authorization')[0], 6)), 2) + [1 => ''];
        $this->log[] = [$method, $path, $user, $body];

        $account = $this->systemUsers[$user] ?? null;
        if (! $account || $account['password'] !== $pass) {
            return Http::response(['error' => 401, 'message' => 'Unauthorized'], 401);
        }
        $isAdmin = $account['group'] === 'full';
        if (str_starts_with($path, '/user') && ! $isAdmin) {
            return Http::response(['error' => 403, 'message' => 'Forbidden'], 403);
        }

        return match (true) {
            $path === '/system/resource' && $method === 'GET' => Http::response([
                'board-name' => 'hAP ax2', 'version' => '7.15.2 (stable)', 'cpu-load' => '4', 'free-memory' => '700000000',
                'total-memory' => '1073741824', 'uptime' => '1d2h3m4s',
            ]),
            $path === '/ip/hotspot' && $method === 'GET' => Http::response($this->servers),
            $path === '/user/group' || str_starts_with($path, '/user/group/') => $this->crud($this->groups, $method, $path, '/user/group', $body),
            $path === '/user' && $method === 'GET' => Http::response(array_map(fn ($n, $u) => ['.id' => $u['id'], 'name' => $n, 'group' => $u['group']], array_keys($this->systemUsers), $this->systemUsers)),
            $path === '/user' && $method === 'PUT' => $this->createSystemUser($body),
            str_starts_with($path, '/user/') && $method === 'PATCH' => $this->patchSystemUser(substr($path, 6), $body),
            $path === '/ip/hotspot/user/profile' => $this->crud($this->profiles, $method, $path, '/ip/hotspot/user/profile', $body),
            str_starts_with($path, '/ip/hotspot/user/profile/') => $this->crud($this->profiles, $method, $path, '/ip/hotspot/user/profile', $body),
            $path === '/ip/hotspot/user' || str_starts_with($path, '/ip/hotspot/user/') => $this->crud($this->hotspotUsers, $method, $path, '/ip/hotspot/user', $body),
            $path === '/ip/hotspot/active' && $method === 'GET' => Http::response(array_values($this->active)),
            str_starts_with($path, '/ip/hotspot/active/') && $method === 'DELETE' => $this->deleteActive(substr($path, 19)),
            default => Http::response(['error' => 404, 'message' => 'Not Found'], 404),
        };
    }

    private function crud(array &$store, string $method, string $path, string $base, array $body)
    {
        if ($method === 'GET') {
            return Http::response(array_values($store));
        }
        if ($method === 'PUT') {
            $id = '*'.strtoupper(dechex($this->seq++));
            $store[$id] = ['.id' => $id] + $body;

            return Http::response($store[$id], 201);
        }
        $id = urldecode(substr($path, strlen($base) + 1));
        if (! isset($store[$id])) {
            return Http::response(['error' => 404, 'message' => 'no such item'], 404);
        }
        if ($method === 'PATCH') {
            $store[$id] = array_merge($store[$id], $body);

            return Http::response($store[$id]);
        }
        unset($store[$id]);

        return Http::response('', 204);
    }

    private function createSystemUser(array $body)
    {
        $this->systemUsers[$body['name']] = ['id' => '*'.strtoupper(dechex($this->seq++)), 'password' => $body['password'], 'group' => $body['group']];

        return Http::response(['.id' => $this->systemUsers[$body['name']]['id']], 201);
    }

    private function patchSystemUser(string $id, array $body)
    {
        foreach ($this->systemUsers as $name => $u) {
            if ($u['id'] === $id) {
                $this->systemUsers[$name] = array_merge($u, array_intersect_key($body, array_flip(['password', 'group'])));

                return Http::response([]);
            }
        }

        return Http::response(['error' => 404], 404);
    }

    private function deleteActive(string $id)
    {
        unset($this->active[urldecode($id)]);

        return Http::response('', 204);
    }

    /** Requêtes reçues, filtrées par méthode et chemin (préfixe). @return array<int, array> */
    public function requests(string $method, string $prefix): array
    {
        return array_values(array_filter($this->log, fn ($r) => $r[0] === $method && str_starts_with($r[1], $prefix)));
    }
}
