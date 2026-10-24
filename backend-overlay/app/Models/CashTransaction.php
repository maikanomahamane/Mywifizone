<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Journal de caisse : append-only. Une erreur se corrige par une écriture ADJUSTMENT, jamais par modification. */
class CashTransaction extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['amount' => 'integer', 'metadata' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->getKey(),
            'cash_session_id' => $this->cash_session_id,
            'type' => $this->type,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'sale_id' => $this->sale_id,
            'user_id' => $this->user_id,
            'note' => $this->note,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
