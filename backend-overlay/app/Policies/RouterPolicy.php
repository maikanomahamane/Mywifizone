<?php

namespace App\Policies;

class RouterPolicy extends TenantPolicy
{
    protected function viewPermission(): string
    {
        return 'routers.view';
    }

    protected function managePermission(): string
    {
        return 'routers.manage';
    }
}
