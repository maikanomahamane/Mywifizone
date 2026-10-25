<?php

namespace App\Services;

use App\Infrastructure\RouterOs\RouterOsException;
use App\Models\NetworkEvent;
use App\Models\Router;
use InvalidArgumentException;

/**
 * Joue, pour un routeur en connexion directe, le rôle que l'agent joue ailleurs : prend les commandes de la file, les
 * exécute via l'API REST de RouterOS, enregistre le résultat (mêmes transitions et mêmes effets métier que pour l'agent),
 * et relève santé + sessions. Doit être appelé DANS le contexte tenant du routeur.
 */
final class RestRouterRunner
{
    public function __construct(
        private readonly RestClientFactory $clients,
        private readonly RouterOsCommandExecutor $executor,
        private readonly CommandQueue $queue,
        private readonly CommandResults $results,
        private readonly RouterTelemetry $telemetry,
    ) {}

    /** Exécute les commandes en attente (quelques paquets au plus par passage). */
    public function drain(Router $router): int
    {
        if (! $router->isRest() || ! $router->isEnrolled()) {
            return 0;
        }

        $handled = 0;
        $down = null;

        for ($round = 0; $round < 5; $round++) {
            $commands = $this->queue->lease($router, (int) config('network.poll_batch'));
            if ($commands->isEmpty()) {
                break;
            }

            try {
                $client = $this->clients->forRouter($router);
            } catch (RouterOsException $e) {
                $down = $e;
            }

            foreach ($commands as $command) {
                $handled++;

                // Routeur déjà constaté injoignable dans ce passage : on ne retente pas le réseau pour chaque commande.
                if ($down !== null) {
                    $done = $this->queue->fail($command, $down->getMessage(), $down->isRetryable());
                } else {
                    try {
                        $result = $this->executor->execute($client, $command->type, (array) $command->payload);
                        $done = $this->queue->complete($command, $result);
                        $this->telemetry->markSeen($router);
                        $this->clearError($router);
                    } catch (InvalidArgumentException $e) {
                        $done = $this->queue->fail($command, $e->getMessage(), false);
                    } catch (RouterOsException $e) {
                        $done = $this->queue->fail($command, $e->getMessage(), $e->isRetryable());
                        $this->recordError($router, $e);
                        if (in_array($e->kind, ['unreachable', 'timeout', 'tls', 'auth', 'forbidden', 'target'], true)) {
                            $down = $e;
                        }
                    }
                }

                if ($done !== null) {
                    $this->results->apply($done);
                }
            }

            if ($down !== null) {
                break;
            }
        }

        return $handled;
    }

    /** Relève santé et sessions actives (équivalent du heartbeat de l'agent). Retourne faux si le routeur ne répond pas. */
    public function heartbeat(Router $router): bool
    {
        if (! $router->isRest() || ! $router->isEnrolled()) {
            return false;
        }

        try {
            $client = $this->clients->forRouter($router);
            $health = RouterOsCommandExecutor::health($client->resource());
            $sessions = RouterOsCommandExecutor::collectSessions($client);
        } catch (RouterOsException $e) {
            $this->recordError($router, $e);
            $router->forceFill(['rest_checked_at' => now()])->save();

            return false;
        }

        $this->telemetry->markSeen($router);
        $this->telemetry->ingestHealth($router, $health);
        $this->telemetry->ingestSessions($router, $sessions['sessions'], $sessions['finished_codes']);
        $this->clearError($router);
        $router->forceFill(['rest_checked_at' => now()])->save();

        return true;
    }

    private function recordError(Router $router, RouterOsException $e): void
    {
        $first = $router->rest_error === null;
        $router->forceFill(['rest_error' => mb_substr($e->getMessage(), 0, 255)])->save();

        if ($first) {
            NetworkEvent::record($router->tenant_id, $router->getKey(), 'ROUTER_CONNECTION_FAILED', 'WARNING', null, ['kind' => $e->kind]);
        }
    }

    private function clearError(Router $router): void
    {
        if ($router->rest_error !== null) {
            $router->forceFill(['rest_error' => null])->save();
        }
    }
}
