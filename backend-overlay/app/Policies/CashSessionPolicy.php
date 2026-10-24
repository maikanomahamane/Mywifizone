<?php

namespace App\Policies;

use App\Models\CashSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class CashSessionPolicy extends TenantPolicy
{
    protected function viewPermission(): string
    {
        return 'cash.view';
    }

    protected function managePermission(): string
    {
        return 'cash.manage';
    }

    /** Un caissier ne voit que ses sessions ; `cash.manage` voit toutes celles du tenant. */
    public function view(User $user, Model $model): bool
    {
        return parent::view($user, $model)
            && ($user->hasPermission('cash.manage') || $model->opened_by === $user->getKey());
    }

    public function open(User $user): bool
    {
        return $user->hasPermission('cash.operate');
    }

    /** Écrire / clôturer : sa propre session, ou toute session du tenant pour `cash.manage`. */
    public function operate(User $user, CashSession $session): bool
    {
        return $user->tenant_id === $session->tenant_id
            && $user->hasPermission('cash.operate')
            && ($session->opened_by === $user->getKey() || $user->hasPermission('cash.manage'));
    }

    public function update(User $user, Model $model): bool
    {
        return false;
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
