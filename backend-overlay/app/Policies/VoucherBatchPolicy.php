<?php

namespace App\Policies;

class VoucherBatchPolicy extends TenantPolicy
{
    protected function viewPermission(): string
    {
        return 'tickets.view';
    }

    protected function managePermission(): string
    {
        return 'tickets.create';
    }
}
