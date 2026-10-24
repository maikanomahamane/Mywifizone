<?php

namespace App\Services;

use App\Models\Router;
use App\Support\ApiException;

/** Choisit le connecteur d'un routeur d'après son mode de connexion. */
final class ConnectorManager
{
    /** @var array<string, NetworkConnector> */
    private array $connectors;

    public function __construct(AgentConnector $agent, RestApiConnector $rest)
    {
        $this->connectors = [$agent->mode() => $agent, $rest->mode() => $rest];
    }

    public function for(Router $router): NetworkConnector
    {
        $connector = $this->connectors[$router->connection_mode] ?? null;

        if (! $connector || ! $connector->isAvailable()) {
            throw new ApiException('connector_unavailable', 'Aucun connecteur disponible pour ce routeur.', 409);
        }

        return $connector;
    }
}
