<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouterCommand extends Model
{
    use BelongsToTenant, HasUuids;

    // Microsecondes : l'ordre de livraison à l'agent suit l'ordre de création (profil avant vouchers).
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'available_at' => 'datetime',
            'leased_until' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    /** @return array<string, mixed> vue propriétaire (diagnostic) */
    public function toPayload(): array
    {
        return [
            'id' => $this->getKey(),
            'router_id' => $this->router_id,
            'type' => $this->type,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'max_attempts' => $this->max_attempts,
            'executed_at' => $this->executed_at?->toIso8601String(),
            'error' => $this->error,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> vue agent : uniquement ce qui est nécessaire à l'exécution */
    public function toAgentPayload(): array
    {
        return ['id' => $this->getKey(), 'type' => $this->type, 'payload' => $this->payload, 'attempt' => $this->attempts];
    }
}
