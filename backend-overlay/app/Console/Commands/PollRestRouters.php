<?php

namespace App\Console\Commands;

use App\Jobs\RunRestRouter;
use App\Models\Router;
use Illuminate\Console\Command;

class PollRestRouters extends Command
{
    protected $signature = 'routers:poll-rest';

    protected $description = 'Routeurs en connexion directe : exécute les commandes en attente et relève santé/sessions.';

    public function handle(): int
    {
        $due = now()->subSeconds((int) config('network.rest.heartbeat_seconds'));
        $count = 0;

        Router::withoutGlobalScopes()
            ->where('connection_mode', 'REST')->whereNotNull('rest_password')
            ->chunkById(100, function ($routers) use ($due, &$count) {
                foreach ($routers as $router) {
                    $heartbeat = $router->rest_checked_at === null || $router->rest_checked_at->lt($due);
                    RunRestRouter::dispatch($router->getKey(), $router->tenant_id, $heartbeat);
                    $count++;
                }
            });

        $this->line("routeurs traités: $count");

        return self::SUCCESS;
    }
}
