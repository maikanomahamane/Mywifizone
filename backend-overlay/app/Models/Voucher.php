<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Voucher extends Model
{
    use BelongsToTenant, HasUuids;

    public const SYNC_PENDING = 'PENDING';

    public const SYNC_SYNCED = 'SYNCED';

    public const SYNC_FAILED = 'FAILED';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'duration_minutes' => 'integer',
            'device_limit' => 'integer',
            'data_limit_mb' => 'integer',
            'is_vip' => 'boolean',
            'valid_until' => 'datetime',
            'sold_at' => 'datetime',
            'activated_at' => 'datetime',
            'revoked_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(HotspotProfile::class, 'profile_id');
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    /** Données envoyées à l'agent pour créer l'utilisateur HotSpot (le code sert d'identifiant ET de mot de passe). */
    public function toAgentPayload(string $profileName): array
    {
        return [
            'voucher_id' => $this->getKey(),
            'code' => $this->code,
            'profile' => $profileName,
            'limit_uptime_minutes' => $this->duration_minutes,
            'limit_bytes_total_mb' => $this->data_limit_mb,
            'comment' => 'wz:'.$this->getKey(),
        ];
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->getKey(),
            'code' => $this->code,
            'status' => $this->status,
            'sync_status' => $this->sync_status,
            'site_id' => $this->site_id,
            'router_id' => $this->router_id,
            'batch_id' => $this->batch_id,
            'profile_id' => $this->profile_id,
            'price' => $this->price,
            'currency' => $this->currency,
            'duration_minutes' => $this->duration_minutes,
            'rate_limit' => $this->rate_limit,
            'device_limit' => $this->device_limit,
            'data_limit_mb' => $this->data_limit_mb,
            'is_vip' => $this->is_vip,
            'valid_until' => $this->valid_until?->toIso8601String(),
            'sold_at' => $this->sold_at?->toIso8601String(),
            'activated_at' => $this->activated_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'label' => $this->metadata['label'] ?? null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
