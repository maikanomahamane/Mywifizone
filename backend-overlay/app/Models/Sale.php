<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Sale extends Model
{
    use BelongsToTenant, HasUuids;

    public const COMPLETED = 'COMPLETED';

    public const VOIDED = 'VOIDED';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'sold_at' => 'datetime', 'voided_at' => 'datetime'];
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    /** @return array<string, mixed> */
    public function toPayload(bool $withCode = false): array
    {
        return [
            'id' => $this->getKey(),
            'site_id' => $this->site_id,
            'router_id' => $this->router_id,
            'voucher_id' => $this->voucher_id,
            'profile_id' => $this->profile_id,
            'agent_id' => $this->agent_id,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'payment_method' => $this->payment_method,
            'provider_transaction_id' => $this->provider_transaction_id,
            'status' => $this->status,
            'sold_at' => $this->sold_at?->toIso8601String(),
            'voided_at' => $this->voided_at?->toIso8601String(),
            'void_reason' => $this->void_reason,
            // Le code est remis au client à la vente ; on ne le renvoie que dans la réponse de création.
            'voucher_code' => $withCode ? $this->voucher?->code : null,
        ];
    }
}
