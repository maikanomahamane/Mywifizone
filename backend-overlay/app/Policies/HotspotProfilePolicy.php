<?php

namespace App\Policies;

class HotspotProfilePolicy extends TenantPolicy
{
    protected function viewPermission(): string
    {
        return 'profiles.view';
    }

    protected function managePermission(): string
    {
        return 'profiles.manage';
    }
}
