<?php

namespace Database\Seeders;

use App\Models\PaymentProviderSetting;
use Illuminate\Database\Seeder;

/**
 * Seuls les noms sont renseignés : les pays/devises supportés seront saisis par le Super Admin
 * une fois les contrats et la documentation officielle obtenus (aucune couverture n'est supposée ici).
 * Les adaptateurs réels restent inutilisables tant que leurs identifiants ne sont pas configurés.
 */
class PaymentProviderSeeder extends Seeder
{
    public function run(): void
    {
        $providers = [
            'mock' => ['Fournisseur de test (dev uniquement)', (bool) config('payments.mock.enabled')],
            'wave' => ['Wave', false],
            'nita' => ['Nita', false],
            'airtel' => ['Airtel Money', false],
            'card' => ['Carte bancaire (Visa/Mastercard)', false],
        ];

        foreach ($providers as $code => [$name, $enabled]) {
            PaymentProviderSetting::firstOrCreate(['code' => $code], ['name' => $name, 'enabled' => $enabled]);
        }
    }
}
