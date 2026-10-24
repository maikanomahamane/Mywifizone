<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CashSession extends Model
{
    use BelongsToTenant, HasUuids;

    public const OPEN = 'OPEN';

    public const CLOSED = 'CLOSED';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'integer', 'expected_balance' => 'integer', 'counted_balance' => 'integer',
            'difference' => 'integer', 'opened_at' => 'datetime', 'closed_at' => 'datetime',
        ];
    }

    /** Solde théorique actuel = somme signée des écritures (OPEN inclus). */
    public function currentBalance(): int
    {
        return (int) CashTransaction::where('cash_session_id', $this->getKey())->sum('amount');
    }

    /** @return array<string, mixed> */
    public function toPayload(bool $withBalance = true): array
    {
        return [
            'id' => $this->getKey(),
            'cash_register_id' => $this->cash_register_id,
            'status' => $this->status,
            'currency' => $this->currency,
            'opened_by' => $this->opened_by,
            'closed_by' => $this->closed_by,
            'opening_balance' => $this->opening_balance,
            'current_balance' => $withBalance && $this->status === self::OPEN ? $this->currentBalance() : null,
            'expected_balance' => $this->expected_balance,
            'counted_balance' => $this->counted_balance,
            'difference' => $this->difference,
            'opened_at' => $this->opened_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'note' => $this->note,
        ];
    }
}
