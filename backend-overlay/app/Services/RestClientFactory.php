<?php

namespace App\Services;

use App\Domain\Network\RestTarget;
use App\Infrastructure\RouterOs\RouterOsException;
use App\Infrastructure\RouterOs\RouterOsRestClient;
use App\Models\Router;

/** Fabrique les clients RouterOS en appliquant les garde-fous de destination (SSRF). */
class RestClientFactory
{
    /**
     * Résout et valide la destination, puis retourne [IP à utiliser, client]. L'IP validée est celle à laquelle on se connecte.
     *
     * @throws RouterOsException kind=forbidden si la destination n'est pas autorisée
     */
    public function make(string $host, int $port, string $username, string $password, bool $verifyTls): RouterOsRestClient
    {
        return new RouterOsRestClient($host, $port, $username, $password, $verifyTls, $this->assertTarget($host, $port));
    }

    public function forRouter(Router $router): RouterOsRestClient
    {
        if ($router->connection_mode !== 'REST' || $router->rest_host === null || $router->rest_password === null) {
            throw new RouterOsException('target', 'Ce routeur n\'est pas configuré en connexion directe.');
        }

        return $this->make($router->rest_host, (int) $router->rest_port, (string) $router->rest_username, $router->rest_password, (bool) $router->rest_verify_tls);
    }

    /** @return string IP validée à épingler pour la connexion */
    public function assertTarget(string $host, int $port): string
    {
        $allowPrivate = (bool) config('network.rest.allow_private');

        if (! RestTarget::isValidHostSyntax($host)) {
            throw new RouterOsException('target', 'Adresse du routeur invalide (IPv4 ou nom de domaine, sans http:// ni port).');
        }
        if (! RestTarget::isAllowedPort($port, (array) config('network.rest.forbidden_ports'))) {
            throw new RouterOsException('target', 'Port non autorisé.');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->resolve($host);
        if ($ips === []) {
            throw new RouterOsException('unreachable', 'Le nom de domaine du routeur ne se résout pas.');
        }
        foreach ($ips as $ip) {
            if (RestTarget::isForbiddenIp($ip, $allowPrivate)) {
                throw new RouterOsException('target', 'Cette adresse n\'est pas autorisée (réseau privé ou réservé : le serveur doit joindre le routeur par une adresse publique).');
            }
        }

        return $ips[0];
    }

    /** @return array<int, string> */
    protected function resolve(string $host): array
    {
        $ips = @gethostbynamel($host);

        return is_array($ips) ? $ips : [];
    }
}
