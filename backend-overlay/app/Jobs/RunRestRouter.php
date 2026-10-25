<?php

namespace App\Jobs;

use App\Domain\Tenancy\TenantContext;
use App\Models\Router;
use App\Services\RestRouterRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Traite un routeur en connexion directe : commandes en attente, puis (si demandé) relevé santé + sessions. */
class RunRestRouter implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly string $routerId, public readonly string $tenantId, public readonly bool $heartbeat = false) {}

    public function handle(TenantContext $context, RestRouterRunner $runner): void
    {
        $context->run($this->tenantId, function () use ($runner) {
            $router = Router::find($this->routerId);
            if (! $router || ! $router->isRest()) {
                return;
            }

            $runner->drain($router);
            if ($this->heartbeat) {
                $runner->heartbeat($router->refresh());
            }
        });
    }
}
