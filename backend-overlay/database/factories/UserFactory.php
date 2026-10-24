<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password-123',
            'locale' => 'fr',
        ];
    }

    /** tenant_id / role_id ne sont pas fillable : on les pose après make(). */
    public function forTenant(Tenant $tenant, string $roleCode = Role::OWNER): static
    {
        return $this->afterMaking(function (User $user) use ($tenant, $roleCode) {
            $user->tenant_id = $tenant->getKey();
            $user->role_id = Role::where('code', $roleCode)->firstOrFail()->getKey();
        });
    }

    /** Utilisateur de la plateforme (sans tenant) : role_id est obligatoire en base. */
    public function withRole(string $roleCode): static
    {
        return $this->afterMaking(function (User $user) use ($roleCode) {
            $user->role_id = Role::where('code', $roleCode)->firstOrFail()->getKey();
        });
    }
}
