<?php

namespace App\Services;

use App\Domain\Business\PaymentMethod;
use App\Domain\Network\VoucherStatus;
use App\Models\CashSession;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Voucher;
use App\Support\ApiException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Vente d'un voucher du stock. Atomique : voucher AVAILABLE -> SOLD, vente, écriture de caisse (espèces) ou rien.
 * Si une étape échoue, aucun voucher n'est marqué vendu.
 */
final class SaleService
{
    public function __construct(
        private readonly VoucherService $vouchers,
        private readonly CashService $cash,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{voucher_id?: ?string, router_id?: ?string, profile_id?: ?string, payment_method: string, provider_transaction_id?: ?string, client_ref?: ?string}  $data
     * @return array{0: Sale, 1: bool} [vente, créée ?] — faux quand `client_ref` rejoue une vente déjà enregistrée
     */
    public function create(User $seller, array $data): array
    {
        $ref = $data['client_ref'] ?? null;
        if ($ref !== null && ($existing = Sale::where('client_ref', $ref)->first())) {
            return [$existing, false];
        }

        try {
            $sale = DB::transaction(fn () => $this->createInTransaction($seller, $data));
        } catch (UniqueConstraintViolationException) {
            // Course sur client_ref (rejeu simultané) : on renvoie la vente gagnante ; sinon c'est la référence de paiement.
            if ($ref !== null && ($existing = Sale::where('client_ref', $ref)->first())) {
                return [$existing, false];
            }
            throw new ApiException('duplicate_reference', 'Cette référence de transaction a déjà été enregistrée.', 409);
        }

        $this->audit->log('sale.created', $seller, $sale, [
            'amount' => $sale->amount, 'currency' => $sale->currency, 'method' => $sale->payment_method, 'voucher_id' => $sale->voucher_id,
        ]);

        return [$sale, true];
    }

    /** @param array<string, mixed> $data */
    private function createInTransaction(User $seller, array $data): Sale
    {
        $method = $data['payment_method'];
        if (! in_array($method, PaymentMethod::ALL, true)) {
            throw new ApiException('validation_error', 'Moyen de paiement inconnu.', 422);
        }

        $voucher = $this->pickVoucher($data);
        $voucher = $this->vouchers->markSold($voucher, $seller);

        $session = null;
        if ($method === PaymentMethod::CASH) {
            $open = $this->cash->openSessionOf($seller);
            $session = $open && $open->currency === $voucher->currency ? $open : null;
            if ($session === null && config('wifizone.sales.require_cash_session_for_cash')) {
                throw new ApiException('cash_session_required', 'Ouvrez une session de caisse (dans la devise de la vente) avant d\'encaisser en espèces.', 409);
            }
        }

        $now = now();
        $sale = Sale::create([
            'site_id' => $voucher->site_id,
            'router_id' => $voucher->router_id,
            'voucher_id' => $voucher->getKey(),
            'profile_id' => $voucher->profile_id,
            'agent_id' => $seller->getKey(),
            'cash_session_id' => $session?->getKey(),
            'amount' => $voucher->price,
            'currency' => $voucher->currency,
            'payment_method' => $method,
            'provider_transaction_id' => $this->clean($data['provider_transaction_id'] ?? null),
            'client_ref' => $data['client_ref'] ?? null,
            'status' => Sale::COMPLETED,
            'sold_at' => $now,
            'sold_on' => $this->localDate($seller->tenant_id, $now),
        ]);

        if ($session) {
            $this->cash->recordSale($session, $seller, $sale->getKey(), $sale->amount);
        }

        return $sale->setRelation('voucher', $voucher);
    }

    /** @param array<string, mixed> $data */
    private function pickVoucher(array $data): Voucher
    {
        if (! empty($data['voucher_id'])) {
            return Voucher::findOrFail($data['voucher_id']);
        }

        // `skip locked` : deux vendeurs simultanés prennent deux vouchers différents (ignoré par SQLite).
        $voucher = Voucher::where('router_id', $data['router_id'] ?? null)
            ->where('profile_id', $data['profile_id'] ?? null)
            ->where('status', VoucherStatus::AVAILABLE)
            ->where('sync_status', Voucher::SYNC_SYNCED)
            ->orderBy('created_at')->orderBy('id')
            ->lock('for update skip locked')
            ->first();

        if (! $voucher) {
            throw new ApiException('no_voucher_available', 'Aucun voucher disponible pour ce profil sur ce routeur. Générez un lot.', 409);
        }

        return $voucher;
    }

    /**
     * Annulation (erreur de saisie, remboursement). Idempotente. Une vente en espèces génère une écriture inverse dans
     * la session d'origine si elle est ouverte, sinon dans la session ouverte de celui qui annule (même devise).
     *
     * @return array{0: Sale, 1: bool}
     */
    public function void(Sale $sale, User $actor, string $reason, bool $revokeVoucher): array
    {
        $changed = DB::transaction(function () use ($sale, $actor, $reason) {
            $fresh = Sale::lockForUpdate()->findOrFail($sale->getKey());
            if ($fresh->status === Sale::VOIDED) {
                return false;
            }

            if ($fresh->payment_method === PaymentMethod::CASH && $fresh->cash_session_id) {
                $origin = CashSession::find($fresh->cash_session_id);
                $target = $origin && $origin->status === CashSession::OPEN ? $origin : $this->cash->openSessionOf($actor);
                if (! $target || $target->currency !== $fresh->currency) {
                    throw new ApiException('cash_session_required', 'Ouvrez une session de caisse pour enregistrer le remboursement en espèces.', 409);
                }
                $this->cash->reverseSale($target, $actor, $fresh->getKey(), $fresh->amount, $reason);
            }

            $fresh->update(['status' => Sale::VOIDED, 'voided_at' => now(), 'voided_by' => $actor->getKey(), 'void_reason' => mb_substr($reason, 0, 255)]);

            return true;
        });

        $sale->refresh();
        if ($changed) {
            $this->audit->log('sale.voided', $actor, $sale, ['amount' => $sale->amount, 'currency' => $sale->currency, 'reason' => $reason]);
            if ($revokeVoucher && $sale->voucher_id && ($voucher = Voucher::find($sale->voucher_id))) {
                try {
                    $this->vouchers->revoke($voucher, $actor);
                } catch (ApiException) {
                    // Voucher déjà utilisé/expiré : l'annulation comptable reste valable.
                }
            }
        }

        return [$sale, $changed];
    }

    /** Jour calendaire dans le fuseau du tenant (borne des rapports « du jour »). */
    public function localDate(?string $tenantId, \DateTimeInterface $at): string
    {
        return \Carbon\CarbonImmutable::instance($at)->setTimezone(self::timezoneOf($tenantId))->toDateString();
    }

    public static function timezoneOf(?string $tenantId): string
    {
        $tz = $tenantId ? Tenant::query()->whereKey($tenantId)->value('timezone') : null;

        return is_string($tz) && in_array($tz, \DateTimeZone::listIdentifiers(), true) ? $tz : 'Africa/Niamey';
    }

    private function clean(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
