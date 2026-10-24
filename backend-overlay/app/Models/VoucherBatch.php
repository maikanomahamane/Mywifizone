<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VoucherBatch extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'valid_until' => 'datetime'];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(HotspotProfile::class, 'profile_id');
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class, 'batch_id');
    }

    /** @param array<string, int>|null $counts nombre de vouchers par statut */
    public function toPayload(?array $counts = null): array
    {
        return [
            'id' => $this->getKey(),
            'site_id' => $this->site_id,
            'router_id' => $this->router_id,
            'profile_id' => $this->profile_id,
            'label' => $this->label,
            'quantity' => $this->quantity,
            'valid_until' => $this->valid_until?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'counts' => $counts,
        ];
    }
}
