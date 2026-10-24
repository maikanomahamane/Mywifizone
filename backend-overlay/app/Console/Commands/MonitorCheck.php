<?php

namespace App\Console\Commands;

use App\Domain\Observability\AlertRules;
use App\Services\Observability\MetricsCollector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MonitorCheck extends Command
{
    protected $signature = 'monitor:check';

    protected $description = 'Évalue les règles d\'alerte (paiements coincés, commandes payées sans ticket, routeurs muets, file) et journalise.';

    public function handle(MetricsCollector $collector): int
    {
        $alerts = AlertRules::evaluate($collector->snapshot(), (array) config('monitoring.thresholds'));

        foreach ($alerts as $a) {
            $context = ['alert' => $a['rule'], 'value' => $a['value'], 'threshold' => $a['threshold']];
            $a['severity'] === AlertRules::CRITICAL
                ? Log::error('ALERT '.$a['rule'], $context)
                : Log::warning('ALERT '.$a['rule'], $context);
            $this->line("[{$a['severity']}] {$a['rule']} = {$a['value']}");
        }
        if ($alerts === []) {
            $this->line('Aucune alerte.');
        }

        // Code de sortie non nul seulement pour une alerte critique : exploitable par un cron ou une supervision externe.
        return in_array(AlertRules::CRITICAL, array_column($alerts, 'severity'), true) ? self::FAILURE : self::SUCCESS;
    }
}
