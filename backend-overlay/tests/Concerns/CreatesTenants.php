<?php

namespace Tests\Concerns;

use App\Domain\Tenancy\TenantContext;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SubscriptionService;
use Database\Seeders\DatabaseSeeder;

trait CreatesTenants
{
    /** Rôles/permissions, devises, formules et fournisseurs de paiement. */
    protected function seedRoles(): void
    {
        $this->seed(DatabaseSeeder::class);
    }

    /**
     * Crée un tenant, son propriétaire et (par défaut) un essai sur la formule indiquée.
     *
     * @return array{0: Tenant, 1: User}
     */
    protected function tenantWithOwner(array $tenantAttrs = [], bool $trial = true, string $plan = 'STARTER'): array
    {
        $tenant = Tenant::factory()->create($tenantAttrs);
        $owner = User::factory()->forTenant($tenant, Role::OWNER)->create();

        if ($trial) {
            $this->asTenant($tenant, fn () => app(SubscriptionService::class)->startTrial(
                $tenant,
                SubscriptionPlan::where('code', $plan)->firstOrFail(),
            ));
        }

        return [$tenant, $owner];
    }

    protected function agentFor(Tenant $tenant): User
    {
        return User::factory()->forTenant($tenant, Role::AGENT)->create();
    }

    protected function superAdmin(): User
    {
        return User::factory()->withRole(Role::SUPER_ADMIN)->create(); // sans tenant
    }

    /** Exécute un bloc avec un contexte tenant (pour les tests de modèles hors HTTP). */
    protected function asTenant(Tenant $tenant, callable $fn): mixed
    {
        return app(TenantContext::class)->run($tenant->getKey(), $fn);
    }
}
