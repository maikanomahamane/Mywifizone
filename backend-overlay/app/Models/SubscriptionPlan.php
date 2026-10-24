<?php

namespace App\Models;

use App\Domain\Money\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    use HasUuids;

    public const MONTHLY = 'MONTHLY';

    public const YEARLY = 'YEARLY';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'monthly_price' => 'integer',
            'yearly_price' => 'integer',
            'trial_days' => 'integer',
            'grace_days' => 'integer',
            'limits' => 'array',
            'features' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function priceFor(string $cycle): ?Money
    {
        $amount = $cycle === self::YEARLY ? $this->yearly_price : $this->monthly_price;

        return $amount === null ? null : Money::of($amount, $this->currency);
    }

    public function trialDays(): int
    {
        return $this->trial_days ?? (int) config('wifizone.trial_days');
    }

    public function graceDays(): int
    {
        return $this->grace_days ?? (int) config('wifizone.grace_days');
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'currency' => $this->currency,
            'monthly_price' => $this->monthly_price,
            'yearly_price' => $this->yearly_price,
            'trial_days' => $this->trialDays(),
            'limits' => $this->limits,
            'features' => $this->features,
        ];
    }
}
