<?php

namespace App\Policies;

use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class SalePolicy extends TenantPolicy
{
    protected function viewPermission(): string
    {
        return 'sales.view';
    }

    protected function managePermission(): string
    {
        return 'sales.create';
    }

    /** Un agent ne voit que ses propres ventes ; `sales.view_all` (propriétaire) voit celles de tout le tenant. */
    public function view(User $user, Model $model): bool
    {
        return parent::view($user, $model)
            && ($user->hasPermission('sales.view_all') || $model->agent_id === $user->getKey());
    }

    public function update(User $user, Model $model): bool
    {
        return false; // une vente ne se modifie pas : elle s'annule
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function void(User $user, Sale $sale): bool
    {
        return $user->hasPermission('sales.void') && $user->tenant_id === $sale->tenant_id;
    }
}
