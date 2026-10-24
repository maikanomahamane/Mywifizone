<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    /** Catalogue de permissions (les modules des phases suivantes y puisent). */
    private const OWNER_PERMISSIONS = [
        'tenant.manage',
        'sites.view', 'sites.manage',
        'routers.view', 'routers.manage',
        'profiles.view', 'profiles.manage',
        'sessions.view', 'sessions.disconnect',
        'tickets.view', 'tickets.create', 'tickets.revoke', 'tickets.export',
        'sales.view', 'sales.view_all', 'sales.create', 'sales.void',
        'cash.view', 'cash.operate', 'cash.manage',
        'reports.view',
        'agents.manage',
        'subscription.view', 'subscription.manage',
    ];

    private const AGENT_PERMISSIONS = [
        'sites.view',
        'routers.view', 'profiles.view', 'sessions.view',
        'tickets.view', 'tickets.create', 'tickets.export',
        'sales.view', 'sales.create',
        'cash.view', 'cash.operate',
    ];

    private const ADMIN_PERMISSIONS = [
        'admin.tenants.manage', 'admin.plans.manage', 'admin.providers.manage', 'admin.payments.view',
        'admin.audit.view', 'admin.platform.view',
    ];

    public function run(): void
    {
        $roles = [
            Role::SUPER_ADMIN => ['Super administrateur', self::ADMIN_PERMISSIONS],
            Role::OWNER => ['Propriétaire', self::OWNER_PERMISSIONS],
            Role::AGENT => ['Agent', self::AGENT_PERMISSIONS],
        ];

        foreach ($roles as $code => [$label, $codes]) {
            $role = Role::updateOrCreate(['code' => $code], ['label' => $label]);

            $ids = collect($codes)
                ->map(fn (string $c) => Permission::firstOrCreate(['code' => $c])->getKey())
                ->all();

            $role->permissions()->sync($ids);
        }
    }
}
