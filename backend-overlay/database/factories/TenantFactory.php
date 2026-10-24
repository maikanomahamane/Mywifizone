<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Tenant> */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        return ['name' => fake()->company(), 'country' => 'NE', 'locale' => 'fr', 'currency' => 'XOF'];
    }

    public function suspended(): static
    {
        return $this->state(['status' => Tenant::STATUS_SUSPENDED]);
    }
}
