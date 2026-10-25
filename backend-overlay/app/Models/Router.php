<?php

namespace App\Models;

use App\Domain\Network\RouterStatus;
use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Router extends Model
{
    use BelongsToTenant, HasUuids;

    // tenant_id, statut, jetons : jamais depuis la requête.
    protected $fillable = ['site_id', 'name', 'model', 'hotspot_server'];

    protected $hidden = ['enrollment_hash', 'agent_token_hash', 'rest_password'];

    protected function casts(): array
    {
        return [
            'health' => 'array',
            'clients_count' => 'integer',
            'enrollment_expires_at' => 'datetime',
            'agent_enrolled_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'rest_password' => 'encrypted',
            'rest_verify_tls' => 'boolean',
            'rest_checked_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function accessPoints(): HasMany
    {
        return $this->hasMany(AccessPoint::class);
    }

    public function isRest(): bool
    {
        return $this->connection_mode === 'REST';
    }

    /** Prêt à recevoir des commandes : agent enrôlé, ou (connexion directe) compte dédié configuré. */
    public function isEnrolled(): bool
    {
        return $this->isRest() ? $this->rest_password !== null : $this->agent_token_hash !== null;
    }

    public function isOnline(): bool
    {
        return $this->status === RouterStatus::ONLINE;
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->getKey(),
            'site_id' => $this->site_id,
            'name' => $this->name,
            'model' => $this->model,
            'routeros_version' => $this->routeros_version,
            'connection_mode' => $this->connection_mode,
            'status' => $this->status,
            'hotspot_server' => $this->hotspot_server,
            'agent_enrolled' => ! $this->isRest() && $this->isEnrolled(),
            'rest_host' => $this->isRest() ? $this->rest_host : null,
            'rest_port' => $this->isRest() ? $this->rest_port : null,
            'rest_verify_tls' => $this->isRest() ? (bool) $this->rest_verify_tls : null,
            'rest_error' => $this->isRest() ? $this->rest_error : null,
            'enrollment_pending' => $this->enrollment_hash !== null && $this->enrollment_expires_at?->isFuture() === true,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'health' => $this->health,
            'clients_count' => $this->clients_count,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
