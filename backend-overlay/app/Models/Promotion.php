<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Promotion extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'max_redemptions' => 'integer',
            'redemptions_count' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function isRedeemable(?string $planCode = null, ?Carbon $at = null): bool
    {
        $at ??= now();

        return $this->is_active
            && ($this->starts_at === null || $this->starts_at <= $at)
            && ($this->ends_at === null || $this->ends_at > $at)
            && ($this->max_redemptions === null || $this->redemptions_count < $this->max_redemptions)
            && ($this->plan_code === null || $planCode === null || $this->plan_code === $planCode);
    }
}
