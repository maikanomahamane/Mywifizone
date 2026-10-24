<?php

namespace App\Policies;

class CashRegisterPolicy extends TenantPolicy
{
    protected function viewPermission(): string
    {
        return 'cash.view';
    }

    protected function managePermission(): string
    {
        return 'cash.manage';
    }
}
