<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Base des policies de ressources rattachées à un tenant : permission RBAC ET appartenance au tenant de l'utilisateur
 * (défense en profondeur : le scope global et le middleware de permission font déjà la même vérification).
 */
abstract class TenantPolicy
{
    abstract protected function viewPermission(): string;

    abstract protected function managePermission(): string;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission($this->viewPermission());
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasPermission($this->viewPermission()) && $user->tenant_id === $model->tenant_id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermission($this->managePermission());
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission($this->managePermission()) && $user->tenant_id === $model->tenant_id;
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->update($user, $model);
    }
}
