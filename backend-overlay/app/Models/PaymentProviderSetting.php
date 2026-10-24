<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Réglages d'un fournisseur gérés par le Super Admin (activation, pays/devises). Les secrets restent dans l'environnement. */
class PaymentProviderSetting extends Model
{
    protected $table = 'payment_providers';

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'countries' => 'array', 'currencies' => 'array'];
    }

    public function supportsCurrency(string $currency): bool
    {
        return $this->currencies === null || in_array($currency, $this->currencies, true);
    }
}
