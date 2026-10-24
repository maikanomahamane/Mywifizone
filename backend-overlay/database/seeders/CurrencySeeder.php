<?php

namespace Database\Seeders;

use App\Domain\Money\CurrencyCatalog;
use App\Models\Currency;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        foreach (CurrencyCatalog::DEFAULTS as $code => [$name, $minorUnit, $symbol]) {
            Currency::firstOrCreate(['code' => $code], ['name' => $name, 'minor_unit' => $minorUnit, 'symbol' => $symbol]);
        }
    }
}
