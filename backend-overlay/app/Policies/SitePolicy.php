<?php

namespace App\Policies;

use App\Models\Site;
use App\Models\User;

/**
 * Défense en profondeur : en plus du scope global et du middleware de permission,
 * la policy revérifie l'appartenance au tenant.
 */
class SitePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('sites.view');
    }

    public function view(User $user, Site $site): bool
    {
        return $user->hasPermission('sites.view') && $user->tenant_id === $site->tenant_id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('sites.manage');
    }

    public function update(User $user, Site $site): bool
    {
        return $user->hasPermission('sites.manage') && $user->tenant_id === $site->tenant_id;
    }

    public function delete(User $user, Site $site): bool
    {
        return $this->update($user, $site);
    }
}
