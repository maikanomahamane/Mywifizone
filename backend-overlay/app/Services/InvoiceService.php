<?php

namespace App\Services;

use App\Domain\Billing\DiscountCalculator;
use App\Domain\Billing\PromotionType;
use App\Domain\Money\Money;
use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Models\Promotion;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Support\ApiException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Factures et reçus. Hypothèse à valider avec votre comptable : les numéros sont uniques mais PAS
 * séquentiels (INV-AAAAMMJJ-XXXXXX) ; une numérotation fiscale continue demandera un compteur dédié.
 */
final class InvoiceService
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly AuditLogger $audit,
    ) {}

    public function createForCheckout(Subscription $subscription, SubscriptionPlan $plan, string $cycle, ?Promotion $promotion): Invoice
    {
        $price = $plan->priceFor($cycle)
            ?? throw new ApiException('cycle_unavailable', 'Cette périodicité n\'est pas proposée pour cette formule.', 422);

        $discount = Money::of(0, $price->currency);

        if ($promotion) {
            if ($promotion->type === PromotionType::FIXED && $promotion->currency !== $price->currency) {
                throw ValidationException::withMessages(['promo_code' => ['Cette promotion n\'est pas valable dans la devise de la formule.']]);
            }
            $discount = DiscountCalculator::discount($price, $promotion->type, $promotion->value);
        }

        $invoice = Invoice::create([
            'subscription_id' => $subscription->getKey(),
            'plan_id' => $plan->getKey(),
            'promotion_id' => $promotion?->getKey(),
            'number' => $this->uniqueNumber('INV', 'number'),
            'status' => Invoice::OPEN,
            'billing_cycle' => $cycle,
            'currency' => $price->currency,
            'subtotal' => $price->amount,
            'discount' => $discount->amount,
            'total' => $price->subtract($discount)->amount,
        ]);

        $this->audit->log('invoice.created', null, $invoice, ['number' => $invoice->number, 'total' => $invoice->total, 'currency' => $invoice->currency]);

        return $invoice;
    }

    /** @return bool false si la facture était déjà payée (paiement en double) ou annulée : rien n'est réappliqué */
    public function markPaid(Invoice $invoice, ?PaymentTransaction $transaction): bool
    {
        $invoice = Invoice::lockForUpdate()->findOrFail($invoice->getKey());

        if ($invoice->status !== Invoice::OPEN) {
            return false;
        }

        $invoice->update([
            'status' => Invoice::PAID,
            'paid_at' => now(),
            'receipt_number' => $this->uniqueNumber('RCT', 'receipt_number'),
            'payment_transaction_id' => $transaction?->getKey(),
        ]);

        $this->audit->log('invoice.paid', null, $invoice, ['number' => $invoice->number, 'receipt' => $invoice->receipt_number], $invoice->tenant_id);

        $this->subscriptions->applyPaidInvoice($invoice->fresh('plan'));

        return true;
    }

    public function void(Invoice $invoice): void
    {
        if ($invoice->status === Invoice::OPEN) {
            $invoice->update(['status' => Invoice::VOID]);
            $this->audit->log('invoice.voided', null, $invoice, ['number' => $invoice->number]);
        }
    }

    private function uniqueNumber(string $prefix, string $column): string
    {
        do {
            $number = $prefix.'-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));
        } while (Invoice::withoutGlobalScopes()->where($column, $number)->exists());

        return $number;
    }
}
