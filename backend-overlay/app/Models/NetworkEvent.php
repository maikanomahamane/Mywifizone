<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Journal d'événements réseau : append-only (aucune mise à jour ni suppression applicative). */
class NetworkEvent extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }

    /** @param array<string, mixed> $metadata */
    public static function record(string $tenantId, ?string $routerId, string $type, string $severity = 'INFO', ?string $message = null, array $metadata = []): self
    {
        return static::create([
            'tenant_id' => $tenantId,
            'router_id' => $routerId,
            'type' => $type,
            'severity' => $severity,
            'message' => $message !== null ? mb_substr($message, 0, 255) : null,
            'metadata' => $metadata ?: null,
        ]);
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->getKey(),
            'router_id' => $this->router_id,
            'type' => $this->type,
            'severity' => $this->severity,
            'message' => $this->message,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
