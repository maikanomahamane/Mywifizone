<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class HotspotSession extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'uptime_seconds' => 'integer',
            'bytes_in' => 'integer',
            'bytes_out' => 'integer',
            'started_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->getKey(),
            'router_id' => $this->router_id,
            'username' => $this->username,
            'ip' => $this->ip,
            'mac' => $this->mac,
            'uptime_seconds' => $this->uptime_seconds,
            'bytes_in' => $this->bytes_in,
            'bytes_out' => $this->bytes_out,
            'started_at' => $this->started_at?->toIso8601String(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'active' => $this->ended_at === null,
        ];
    }
}
