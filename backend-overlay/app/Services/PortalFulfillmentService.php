<?php

namespace App\Services;

use App\Domain\Business\PaymentMethod;
use App\Domain\Network\VoucherStatus;
use App\Domain\Payments\PaymentStatus;
use App\Domain\Portal\PortalOrderStatus;
use App\Models\PaymentTransaction;
use App\Models\PortalOrder;
use App\Models\Sale;
use App\Models\Voucher;
use Illuminate\Support\Facades\DB;

/**
 * Livraison d'un pass après paiement CONFIRMÉ (webhook signé, montant vérifié par PaymentService).
 * Découplé du moteur de vouchers : on réserve un voucher AVAILABLE du stock, on enregistre la vente (sans vendeur), on le marque livré.
 * Appelé dans le contexte du tenant et dans la transaction SQL du webhook : tout réussit ou rien n'est écrit.
 */
final class PortalFulfillmentService
{
    public function __construct(
        private readonly VoucherService $vouchers,
        private readonly SaleService $sales,
        private readonly AuditLogger $audit,
    ) {}

    public function onPaid(PortalOrder $order, PaymentTransaction $payment): void
    {
        $fresh = PortalOrder::lockForUpdate()->findOrFail($order->getKey());
        if ($fresh->status === PortalOrderStatus::FULFILLED) {
            return; // idempotent
        }
        if (! PortalOrderStatus::canFulfill($fresh->status)) {
            $this->audit->log('portal.paid_after_failure', null, $fresh, ['status' => $fresh->status], $fresh->tenant_id);
            $fresh->update(['status' => PortalOrderStatus::NEEDS_ATTENTION]);

            return;
        }

        $voucher = Voucher::where('router_id', $fresh->router_id)
            ->where('profile_id', $fresh->profile_id)
            ->where('status', VoucherStatus::AVAILABLE)
            ->where('sync_status', Voucher::SYNC_SYNCED)
            ->orderBy('created_at')->orderBy('id')
            ->lock('for update skip locked')
            ->first();

        if (! $voucher) {
            // L'argent est encaissé mais il n'y a plus de ticket : à traiter par le propriétaire (livraison ou remboursement MANUEL).
            $fresh->update(['status' => PortalOrderStatus::NEEDS_ATTENTION]);
            $this->audit->log('portal.no_stock_after_payment', null, $fresh, ['profile_id' => $fresh->profile_id, 'payment' => $payment->getKey()], $fresh->tenant_id);

            return;
        }

        $voucher = $this->vouchers->markSold($voucher, null);
        $now = now();
        $sale = Sale::create([
            'site_id' => $voucher->site_id,
            'router_id' => $voucher->router_id,
            'voucher_id' => $voucher->getKey(),
            'profile_id' => $voucher->profile_id,
            'agent_id' => null,
            'amount' => $fresh->amount,
            'currency' => $fresh->currency,
            'payment_method' => PaymentMethod::MOBILE_MONEY,
            'provider_transaction_id' => $payment->provider_reference,
            'client_ref' => 'portal-'.$fresh->getKey(),
            'status' => Sale::COMPLETED,
            'sold_at' => $now,
            'sold_on' => $this->sales->localDate($fresh->tenant_id, $now),
        ]);

        $fresh->update(['status' => PortalOrderStatus::FULFILLED, 'voucher_id' => $voucher->getKey(), 'sale_id' => $sale->getKey(), 'fulfilled_at' => $now]);
        $this->audit->log('portal.fulfilled', null, $fresh, ['sale_id' => $sale->getKey(), 'amount' => $fresh->amount, 'currency' => $fresh->currency], $fresh->tenant_id);
    }

    /** Paiement échoué / annulé / expiré. Une commande déjà livrée n'est jamais modifiée. */
    public function onNotPaid(PortalOrder $order, string $paymentStatus): void
    {
        $fresh = PortalOrder::lockForUpdate()->findOrFail($order->getKey());
        if ($fresh->status !== PortalOrderStatus::PENDING_PAYMENT) {
            return;
        }
        $fresh->update(['status' => $paymentStatus === PaymentStatus::EXPIRED ? PortalOrderStatus::EXPIRED : PortalOrderStatus::FAILED]);
    }

    /** Pour les tests et la tâche planifiée : transaction englobante. */
    public function inTransaction(callable $fn): mixed
    {
        return DB::transaction($fn);
    }
}
