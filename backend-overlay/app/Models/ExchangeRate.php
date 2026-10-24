<?php

namespace App\Models;

use App\Domain\Money\ExchangeRate as Rate;
use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['from_currency', 'to_currency', 'rate_scaled'];

    protected function casts(): array
    {
        return ['rate_scaled' => 'integer'];
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->getKey(),
            'from' => $this->from_currency,
            'to' => $this->to_currency,
            'rate' => Rate::format($this->rate_scaled),
            'source' => 'manual',
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
