<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessPoint extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['router_id', 'name', 'model', 'location'];

    protected function casts(): array
    {
        return ['clients_count' => 'integer', 'last_seen_at' => 'datetime'];
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->getKey(),
            'router_id' => $this->router_id,
            'name' => $this->name,
            'model' => $this->model,
            'location' => $this->location,
            'status' => $this->status,
            'clients_count' => $this->clients_count,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
        ];
    }
}
