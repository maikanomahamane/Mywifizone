<?php

namespace App\Policies;

class AccessPointPolicy extends TenantPolicy
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
