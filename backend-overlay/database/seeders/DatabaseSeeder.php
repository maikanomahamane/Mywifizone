<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            CurrencySeeder::class,
            PlanSeeder::class,
            PaymentProviderSeeder::class,
        ]);
        // Le Super Admin se crée via : php artisan wifizone:create-super-admin — jamais de mot de passe dans un seeder.
    }
}
