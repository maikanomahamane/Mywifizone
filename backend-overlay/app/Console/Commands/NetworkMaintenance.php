<?php

namespace App\Console\Commands;

use App\Services\CommandQueue;
use App\Services\CommandResults;
use App\Services\RouterTelemetry;
use Illuminate\Console\Command;

class NetworkMaintenance extends Command
{
    protected $signature = 'network:maintain';

    protected $description = 'Routeurs sans signal -> OFFLINE ; baux de commandes expirés -> remise en file ou échec (avec leurs effets métier).';

    public function handle(RouterTelemetry $telemetry, CommandQueue $queue, CommandResults $results): int
    {
        $offline = $telemetry->markStaleOffline();

        $failed = $queue->reap();
        foreach ($failed as $command) {
            $results->apply($command);   // ex. vouchers dont la création n'a jamais été confirmée -> sync FAILED
        }

        $this->line("routeurs hors ligne: $offline");
        $this->line("commandes échouées: {$failed->count()}");

        return self::SUCCESS;
    }
}
