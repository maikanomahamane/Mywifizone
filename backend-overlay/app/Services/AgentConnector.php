<?php

namespace App\Services;

use App\Models\Router;
use App\Models\RouterCommand;

/** Connecteur par agent sortant : le cloud pose la commande dans la file, l'agent la récupère. */
final class AgentConnector implements NetworkConnector
{
    public function __construct(private readonly CommandQueue $queue) {}

    public function mode(): string
    {
        return 'AGENT';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function send(Router $router, string $type, array $payload, ?string $createdBy = null): RouterCommand
    {
        return $this->queue->enqueue($router, $type, $payload, $createdBy);
    }
}
