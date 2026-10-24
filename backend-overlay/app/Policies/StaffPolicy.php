<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

/** Gestion des agents (utilisateurs AGENT) d'un tenant, réservée à `agents.manage`. */
class StaffPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('agents.manage');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('agents.manage');
    }

    /** Uniquement les AGENT du même tenant : jamais le propriétaire ni un autre tenant. */
    public function manage(User $user, User $target): bool
    {
        return $user->hasPermission('agents.manage')
            && $user->tenant_id !== null
            && $user->tenant_id === $target->tenant_id
            && $target->role?->code === Role::AGENT;
    }
}
