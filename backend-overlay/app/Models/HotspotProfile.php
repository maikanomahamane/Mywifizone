<?php

namespace App\Models;

use App\Domain\Network\RouterOsValue;
use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HotspotProfile extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['name', 'kind', 'price', 'currency', 'duration_minutes', 'rate_limit', 'device_limit', 'data_limit_mb', 'is_active', 'portal_visible'];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'duration_minutes' => 'integer',
            'device_limit' => 'integer',
            'data_limit_mb' => 'integer',
            'is_active' => 'boolean',
            'portal_visible' => 'boolean',
        ];
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class, 'profile_id');
    }

    /** Nom du profil côté routeur (dérivé de l'UUID, jamais libre). */
    public function routerName(): string
    {
        return RouterOsValue::profileName($this->getKey());
    }

    /** Données envoyées à l'agent pour créer/mettre à jour le profil HotSpot du routeur. */
    public function toAgentPayload(): array
    {
        return [
            'name' => $this->routerName(),
            'rate_limit' => $this->rate_limit,
            'shared_users' => $this->device_limit,
        ];
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->getKey(),
            'name' => $this->name,
            'kind' => $this->kind,
            'price' => $this->price,
            'currency' => $this->currency,
            'duration_minutes' => $this->duration_minutes,
            'rate_limit' => $this->rate_limit,
            'device_limit' => $this->device_limit,
            'data_limit_mb' => $this->data_limit_mb,
            'is_active' => $this->is_active,
            'portal_visible' => (bool) $this->portal_visible,
        ];
    }
}
