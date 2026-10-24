<?php

namespace App\Services;

use App\Models\Router;
use App\Models\RouterCommand;
use App\Support\ApiException;

/**
 * Appel direct de l'API REST de RouterOS 7 (HTTPS) quand le routeur a une IP joignable depuis le cloud.
 * NON BRANCHÉ : à construire avec un routeur de test, des identifiants dédiés à permissions minimales stockés
 * chiffrés, des délais d'attente, des tentatives et un coupe-circuit. Voir docs/mikrotik-setup-guide.md.
 */
final class RestApiConnector implements NetworkConnector
{
    public function mode(): string
    {
        return 'REST';
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function send(Router $router, string $type, array $payload, ?string $createdBy = null): RouterCommand
    {
        throw new ApiException('connector_unavailable', 'Le mode de connexion directe (REST) n\'est pas disponible.', 409);
    }
}
