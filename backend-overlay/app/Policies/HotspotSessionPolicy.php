<?php

namespace App\Policies;

use App\Models\HotspotSession;
use App\Models\User;

class HotspotSessionPolicy extends TenantPolicy
{
    protected function viewPermission(): string
    {
        return 'sessions.view';
    }

    protected function managePermission(): string
    {
        return 'sessions.disconnect';
    }

    public function disconnect(User $user, HotspotSession $session): bool
    {
        return $user->hasPermission('sessions.disconnect') && $user->tenant_id === $session->tenant_id;
    }
}
