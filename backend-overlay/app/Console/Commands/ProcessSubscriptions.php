<?php

namespace App\Console\Commands;

use App\Services\SubscriptionService;
use Illuminate\Console\Command;

class ProcessSubscriptions extends Command
{
    protected $signature = 'subscriptions:process';

    protected $description = 'Fait avancer les abonnements : fin d\'essai, échéance, période de grâce, résiliations.';

    public function handle(SubscriptionService $subscriptions): int
    {
        $counts = $subscriptions->process();

        foreach ($counts as $name => $count) {
            $this->line("$name: $count");
        }

        return self::SUCCESS;
    }
}
