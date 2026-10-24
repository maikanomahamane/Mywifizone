<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortalOrder extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = [];

    protected $hidden = ['public_token'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'fulfilled_at' => 'datetime'];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(HotspotProfile::class, 'profile_id');
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    /** Vue propriétaire : jamais le code ni le jeton public. */
    public function toOwnerPayload(): array
    {
        return [
            'id' => $this->getKey(),
            'status' => $this->status,
            'profile_id' => $this->profile_id,
            'profile_name' => $this->profile?->name,
            'router_id' => $this->router_id,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'customer_phone' => $this->customer_phone,
            'sale_id' => $this->sale_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'fulfilled_at' => $this->fulfilled_at?->toIso8601String(),
        ];
    }
}
