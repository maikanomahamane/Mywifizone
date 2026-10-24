<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

/**
 * Prix de lancement du cahier des charges (en XOF, unités mineures = francs entiers).
 * `firstOrCreate` : relancer les seeders n'écrase JAMAIS les prix/limites modifiés par le Super Admin.
 *
 * Hypothèses à valider (le cahier ne chiffre pas ces points) :
 *  - yearly_price = null : pas de formule annuelle tant que le Super Admin n'a pas fixé un prix ;
 *  - PRO : 10 agents ; BUSINESS : limites illimitées (null) ;
 *  - ventilation des fonctionnalités par plan d'après le tableau du §5.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'code' => 'STARTER', 'name' => 'Starter', 'monthly_price' => 2000, 'sort_order' => 1,
                'limits' => ['max_sites' => 1, 'max_routers' => 1, 'max_access_points' => 3, 'max_agents' => 1, 'max_batch_size' => 100],
                'features' => ['tickets', 'vip', 'pdf', 'cash', 'basic_stats'],
            ],
            [
                'code' => 'PRO', 'name' => 'Pro', 'monthly_price' => 5000, 'sort_order' => 2,
                'limits' => ['max_sites' => 3, 'max_routers' => 5, 'max_access_points' => 15, 'max_agents' => 10, 'max_batch_size' => 500],
                'features' => ['tickets', 'vip', 'pdf', 'cash', 'basic_stats', 'qr', 'captive_portal', 'reports', 'multi_currency'],
            ],
            [
                'code' => 'BUSINESS', 'name' => 'Business', 'monthly_price' => 10000, 'sort_order' => 3,
                'limits' => ['max_sites' => null, 'max_routers' => null, 'max_access_points' => null, 'max_agents' => null, 'max_batch_size' => 2000],
                'features' => ['tickets', 'vip', 'pdf', 'cash', 'basic_stats', 'qr', 'captive_portal', 'reports', 'multi_currency', 'advanced_monitoring', 'advanced_reports', 'international'],
            ],
        ];

        foreach ($plans as $plan) {
            SubscriptionPlan::firstOrCreate(
                ['code' => $plan['code']],
                $plan + ['currency' => 'XOF', 'yearly_price' => null, 'is_active' => true],
            );
        }
    }
}
