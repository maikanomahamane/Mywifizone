<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Voucher;
use Illuminate\Database\Eloquent\Model;

class VoucherPolicy extends TenantPolicy
{
    protected function viewPermission(): string
    {
        return 'tickets.view';
    }

    protected function managePermission(): string
    {
        return 'tickets.create';
    }

    public function revoke(User $user, Voucher $voucher): bool
    {
        return $user->hasPermission('tickets.revoke') && $user->tenant_id === $voucher->tenant_id;
    }

    // Les vouchers ne se modifient ni ne se suppriment : ils se révoquent.
    public function update(User $user, Model $model): bool
    {
        return false;
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
