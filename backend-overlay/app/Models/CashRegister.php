<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CashRegister extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['site_id', 'name', 'currency', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->getKey(), 'site_id' => $this->site_id, 'name' => $this->name,
            'currency' => $this->currency, 'is_active' => $this->is_active,
        ];
    }
}
