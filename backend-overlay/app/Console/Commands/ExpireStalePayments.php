<?php

namespace App\Console\Commands;

use App\Services\PaymentService;
use Illuminate\Console\Command;

class ExpireStalePayments extends Command
{
    protected $signature = 'payments:expire-stale';

    protected $description = 'Clôture les paiements restés INITIATED/PENDING trop longtemps (la facture reste ouverte : un paiement tardif est honoré).';

    public function handle(PaymentService $payments): int
    {
        foreach ($payments->expireStale() as $name => $count) {
            $this->line("$name: $count");
        }

        return self::SUCCESS;
    }
}
