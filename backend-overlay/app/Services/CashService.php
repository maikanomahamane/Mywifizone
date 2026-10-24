<?php

namespace App\Services;

use App\Domain\Business\CashType;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\CashTransaction;
use App\Models\User;
use App\Support\ApiException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Caisse : sessions (ouverture -> écritures -> clôture) et journal append-only.
 * Invariants garantis par la base : une seule session ouverte par caisse ET par utilisateur (clés uniques `open_key`).
 */
final class CashService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function open(User $user, CashRegister $register, int $openingBalance, ?string $note = null): CashSession
    {
        if (! $register->is_active) {
            throw new ApiException('cash_register_inactive', 'Cette caisse est désactivée.', 409);
        }
        if ($openingBalance < 0) {
            throw new ApiException('validation_error', 'Le solde initial ne peut pas être négatif.', 422);
        }

        try {
            $session = DB::transaction(function () use ($user, $register, $openingBalance, $note) {
                $session = CashSession::create([
                    'cash_register_id' => $register->getKey(),
                    'opened_by' => $user->getKey(),
                    'status' => CashSession::OPEN,
                    'currency' => $register->currency,
                    'opening_balance' => $openingBalance,
                    'opened_at' => now(),
                    'note' => $note,
                    'open_key' => $register->getKey(),
                    'open_user_key' => $user->getKey(),
                ]);
                $this->write($session, $user, CashType::OPEN, $openingBalance, $note);

                return $session;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ApiException('cash_session_already_open', 'Une session de caisse est déjà ouverte (pour cette caisse ou pour vous).', 409);
        }

        $this->audit->log('cash.opened', $user, $session, ['register' => $register->getKey(), 'opening_balance' => $openingBalance, 'currency' => $session->currency]);

        return $session;
    }

    /** Entrée/sortie manuelle. Les montants sont saisis positifs (sauf ADJUSTMENT, signé) ; le type décide du sens. */
    public function record(CashSession $session, User $user, string $type, int $amount, ?string $note): CashTransaction
    {
        if (! in_array($type, [CashType::CASH_IN, CashType::EXPENSE, CashType::WITHDRAWAL, CashType::ADJUSTMENT], true)) {
            throw new ApiException('validation_error', 'Type d\'opération non autorisé.', 422);
        }
        if ($amount === 0 || ($type !== CashType::ADJUSTMENT && $amount < 0)) {
            throw new ApiException('validation_error', 'Montant invalide.', 422);
        }

        $tx = DB::transaction(function () use ($session, $user, $type, $amount, $note) {
            $fresh = CashSession::lockForUpdate()->findOrFail($session->getKey());
            $this->assertOpen($fresh);

            $signed = CashType::signed($type, $amount);
            if ($signed < 0 && $fresh->currentBalance() + $signed < 0) {
                throw new ApiException('insufficient_cash', 'Le solde de la caisse est insuffisant.', 422, ['balance' => $fresh->currentBalance()]);
            }

            return $this->write($fresh, $user, $type, $signed, $note);
        });

        $this->audit->log('cash.'.strtolower($type), $user, $session, ['amount' => $tx->amount, 'currency' => $tx->currency]);

        return $tx;
    }

    /** Encaissement d'une vente (appelé par SaleService, dans sa transaction). */
    public function recordSale(CashSession $session, User $user, string $saleId, int $amount): CashTransaction
    {
        $fresh = CashSession::lockForUpdate()->findOrFail($session->getKey());
        $this->assertOpen($fresh);

        return $this->write($fresh, $user, CashType::SALE, abs($amount), null, $saleId);
    }

    /** Annulation d'une vente : écriture négative (jamais de suppression). */
    public function reverseSale(CashSession $session, User $user, string $saleId, int $amount, string $reason): CashTransaction
    {
        $fresh = CashSession::lockForUpdate()->findOrFail($session->getKey());
        $this->assertOpen($fresh);

        return $this->write($fresh, $user, CashType::ADJUSTMENT, -abs($amount), 'Annulation vente: '.$reason, $saleId);
    }

    public function close(CashSession $session, User $user, int $counted, ?string $note = null): CashSession
    {
        if ($counted < 0) {
            throw new ApiException('validation_error', 'Le montant compté ne peut pas être négatif.', 422);
        }

        $closed = DB::transaction(function () use ($session, $user, $counted, $note) {
            $fresh = CashSession::lockForUpdate()->findOrFail($session->getKey());
            $this->assertOpen($fresh);

            $expected = $fresh->currentBalance();
            $this->write($fresh, $user, CashType::CLOSE, 0, $note, null, ['expected' => $expected, 'counted' => $counted, 'difference' => $counted - $expected]);

            $fresh->update([
                'status' => CashSession::CLOSED,
                'closed_by' => $user->getKey(),
                'closed_at' => now(),
                'expected_balance' => $expected,
                'counted_balance' => $counted,
                'difference' => $counted - $expected,
                'open_key' => null,
                'open_user_key' => null,
                'note' => $note ?? $fresh->note,
            ]);

            return $fresh;
        });

        $this->audit->log('cash.closed', $user, $closed, [
            'expected' => $closed->expected_balance, 'counted' => $closed->counted_balance, 'difference' => $closed->difference, 'currency' => $closed->currency,
        ]);

        return $closed;
    }

    /** Session ouverte de cet utilisateur, s'il en a une. */
    public function openSessionOf(User $user): ?CashSession
    {
        return CashSession::where('opened_by', $user->getKey())->where('status', CashSession::OPEN)->first();
    }

    private function assertOpen(CashSession $session): void
    {
        if ($session->status !== CashSession::OPEN) {
            throw new ApiException('cash_session_closed', 'Cette session de caisse est clôturée.', 409);
        }
    }

    /** @param array<string, mixed> $metadata */
    private function write(CashSession $session, User $user, string $type, int $amount, ?string $note, ?string $saleId = null, array $metadata = []): CashTransaction
    {
        return CashTransaction::create([
            'cash_session_id' => $session->getKey(),
            'cash_register_id' => $session->cash_register_id,
            'user_id' => $user->getKey(),
            'sale_id' => $saleId,
            'type' => $type,
            'amount' => $amount,
            'currency' => $session->currency,
            'note' => $note !== null ? mb_substr($note, 0, 255) : null,
            'metadata' => $metadata ?: null,
            'created_at' => now(),
        ]);
    }
}
