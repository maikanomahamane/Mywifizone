<?php

namespace App\Services;

use App\Jobs\RunRestRouter;
use App\Models\Router;
use App\Models\RouterCommand;

/**
 * Connexion directe : le serveur appelle l'API REST de RouterOS 7 (HTTPS) avec le compte dédié créé à l'ajout du routeur
 * (mot de passe chiffré en base). Utilisable quand le routeur est joignable depuis le serveur (IP publique, DDNS, VPN).
 * La commande passe par la même file que l'agent (suivi, tentatives, effets métier) ; un traitement est lancé aussitôt.
 */
final class RestApiConnector implements NetworkConnector
{
    public function __construct(private readonly CommandQueue $queue) {}

    public function mode(): string
    {
        return 'REST';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function send(Router $router, string $type, array $payload, ?string $createdBy = null): RouterCommand
    {
        $command = $this->queue->enqueue($router, $type, $payload, $createdBy);

        RunRestRouter::dispatch($router->getKey(), $router->tenant_id)->afterCommit();

        return $command;
    }
}
