<?php

namespace App\Console\Commands;

use App\Services\VoucherService;
use Illuminate\Console\Command;

class ExpireVouchers extends Command
{
    protected $signature = 'vouchers:expire';

    protected $description = 'Passe EXPIRED les vouchers dont l\'échéance est dépassée et désactive leur utilisateur HotSpot.';

    public function handle(VoucherService $vouchers): int
    {
        $this->line('vouchers expirés: '.$vouchers->expireDue());

        return self::SUCCESS;
    }
}
