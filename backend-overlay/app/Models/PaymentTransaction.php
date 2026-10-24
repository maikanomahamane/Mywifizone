<?php

namespace App\Models;

use App\Domain\Money\Money;
use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentTransaction extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'instructions' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function money(): Money
    {
        return Money::of($this->amount, $this->currency);
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->getKey(),
            'provider' => $this->provider,
            'status' => $this->status,
            'reference' => $this->provider_reference,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'instructions' => $this->instructions,
            'failure_reason' => $this->failure_reason,
        ];
    }
}
