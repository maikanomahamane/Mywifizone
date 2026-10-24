<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    use BelongsToTenant, HasUuids;

    public const OPEN = 'OPEN';

    public const PAID = 'PAID';

    public const VOID = 'VOID';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'subtotal' => 'integer',
            'discount' => 'integer',
            'total' => 'integer',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function isPaid(): bool
    {
        return $this->status === self::PAID;
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->getKey(),
            'number' => $this->number,
            'status' => $this->status,
            'plan' => $this->plan?->code,
            'billing_cycle' => $this->billing_cycle,
            'currency' => $this->currency,
            'subtotal' => $this->subtotal,
            'discount' => $this->discount,
            'total' => $this->total,
            'period_start' => $this->period_start?->toIso8601String(),
            'period_end' => $this->period_end?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'receipt_number' => $this->receipt_number,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
