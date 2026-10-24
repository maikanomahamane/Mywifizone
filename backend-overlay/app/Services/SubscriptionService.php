<?php

namespace App\Services;

use App\Domain\Billing\PromotionType;
use App\Domain\Billing\SubscriptionStatus;
use App\Domain\Payments\PaymentStatus;
use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Models\Promotion;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Support\ApiException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cycle de vie d'un abonnement. Les méthodes qui lisent/écrivent Subscription ou Invoice
 * exigent un contexte tenant (TenantContext), sauf process(), suspend(), reactivate() et
 * revertPendingIfIdle() qui filtrent explicitement par tenant.
 */
final class SubscriptionService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Démarre l'essai (14 jours par défaut, ou la durée du plan, + extension éventuelle). */
    public function startTrial(Tenant $tenant, SubscriptionPlan $plan, ?Promotion $trialPromotion = null): Subscription
    {
        $days = $plan->trialDays() + ($trialPromotion?->value ?? 0);

        $subscription = Subscription::create([
            'plan_id' => $plan->getKey(),
            'status' => SubscriptionStatus::TRIAL,
            'trial_ends_at' => now()->addDays($days),
        ]);

        if ($trialPromotion) {
            // Réservation atomique : deux inscriptions simultanées ne peuvent pas dépasser max_redemptions.
            $claimed = Promotion::whereKey($trialPromotion->getKey())
                ->where(fn ($q) => $q->whereNull('max_redemptions')->orWhereColumn('redemptions_count', '<', 'max_redemptions'))
                ->increment('redemptions_count');

            if ($claimed === 0) {
                throw ValidationException::withMessages(['promo_code' => ['Code promotionnel invalide ou expiré.']]);
            }
        }

        $this->audit->log('subscription.trial_started', null, $subscription, ['plan' => $plan->code, 'days' => $days], $tenant->getKey());

        return $subscription;
    }

    /** Résout un code promotionnel ; $trialOnly distingue extension d'essai (inscription) et remise (checkout). */
    public function findPromotion(string $code, ?string $planCode, bool $trialOnly): Promotion
    {
        $promotion = Promotion::where('code', strtoupper(trim($code)))->first();

        $kindOk = $promotion !== null && (($promotion->type === PromotionType::TRIAL_EXTENSION) === $trialOnly);

        if (! $promotion || ! $kindOk || ! $promotion->isRedeemable($planCode)) {
            throw ValidationException::withMessages(['promo_code' => ['Code promotionnel invalide ou expiré.']]);
        }

        return $promotion;
    }

    /** Un checkout lancé depuis un abonnement éteint le met en attente de premier paiement. */
    public function markPendingIfInactive(Subscription $subscription): void
    {
        if (in_array($subscription->status, [SubscriptionStatus::EXPIRED, SubscriptionStatus::CANCELLED], true)) {
            $subscription->update(['status' => SubscriptionStatus::PENDING]);
        }
    }

    /**
     * Quand plus aucun paiement n'est en cours, un abonnement PENDING retourne à EXPIRED : « en attente d'un
     * premier paiement » n'a plus de sens. Les factures restent ouvertes, donc un paiement tardif est toujours honoré.
     */
    public function revertPendingIfIdle(string $tenantId): void
    {
        $subscription = Subscription::withoutGlobalScopes()->where('tenant_id', $tenantId)->lockForUpdate()->first();

        if (! $subscription || $subscription->status !== SubscriptionStatus::PENDING) {
            return;
        }

        $open = PaymentTransaction::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [PaymentStatus::INITIATED, PaymentStatus::PENDING])
            ->exists();

        if ($open) {
            return;
        }

        $subscription->update(['status' => SubscriptionStatus::EXPIRED]);
        $this->audit->log('subscription.pending_reverted', null, $subscription, [], $tenantId);
    }

    /** Applique une facture payée : nouvelle période, statut ACTIVE (sauf SUSPENDED : seul le Super Admin lève la suspension). */
    public function applyPaidInvoice(Invoice $invoice): void
    {
        $subscription = Subscription::lockForUpdate()->findOrFail($invoice->subscription_id);
        $plan = $invoice->plan;

        $stack = $subscription->status === SubscriptionStatus::ACTIVE
            && $subscription->plan_id === $invoice->plan_id
            && $subscription->current_period_end?->isFuture();

        // Renouvellement anticipé : la nouvelle période s'ajoute à la fin de l'ancienne.
        // Changement de formule, essai ou période échue : elle commence maintenant (pas de prorata).
        $start = $stack ? $subscription->current_period_end->copy() : now();
        $end = $invoice->billing_cycle === SubscriptionPlan::YEARLY
            ? $start->copy()->addYearsNoOverflow(1)
            : $start->copy()->addMonthsNoOverflow(1);

        $invoice->update(['period_start' => $start, 'period_end' => $end]);

        $attributes = [
            'plan_id' => $plan->getKey(),
            'billing_cycle' => $invoice->billing_cycle,
            'current_period_start' => $start,
            'current_period_end' => $end,
            'grace_ends_at' => null,
            'cancel_at_period_end' => false,
            'cancelled_at' => null,
        ];

        $suspended = $subscription->status === SubscriptionStatus::SUSPENDED;
        if (! $suspended) {
            $attributes['status'] = SubscriptionStatus::ACTIVE;
        }

        $subscription->update($attributes);

        if ($invoice->promotion_id) {
            Promotion::whereKey($invoice->promotion_id)->increment('redemptions_count');
        }

        $this->audit->log('subscription.activated', null, $subscription, [
            'invoice' => $invoice->number,
            'plan' => $plan->code,
            'period_end' => $end->toIso8601String(),
            'still_suspended' => $suspended,
        ], $subscription->tenant_id);
    }

    public function cancel(Subscription $subscription): Subscription
    {
        $immediate = [SubscriptionStatus::TRIAL, SubscriptionStatus::PENDING];
        $atPeriodEnd = [SubscriptionStatus::ACTIVE, SubscriptionStatus::PAST_DUE];

        if (in_array($subscription->status, $immediate, true)) {
            $subscription->update(['status' => SubscriptionStatus::CANCELLED, 'cancelled_at' => now()]);
        } elseif (in_array($subscription->status, $atPeriodEnd, true)) {
            $subscription->update(['cancel_at_period_end' => true]);
        } else {
            throw new ApiException('subscription_not_cancellable', 'Cet abonnement ne peut pas être annulé.', 409);
        }

        $this->audit->log('subscription.cancel_requested', null, $subscription, ['status' => $subscription->status]);

        return $subscription;
    }

    /**
     * Suspension par le Super Admin : le tenant ne peut plus se connecter ni utiliser son jeton (middleware `tenant`),
     * et son abonnement passe à SUSPENDED (ignoré par process()). Idempotent.
     */
    public function suspend(Tenant $tenant, ?string $reason = null): void
    {
        if ($tenant->isSuspended()) {
            return;
        }

        DB::transaction(function () use ($tenant, $reason) {
            $tenant->update(['status' => Tenant::STATUS_SUSPENDED]);

            $subscription = $tenant->subscription()->lockForUpdate()->first();
            $subscription?->update(['status' => SubscriptionStatus::SUSPENDED]);

            $this->audit->log('tenant.suspended', null, $tenant, ['reason' => $reason], $tenant->getKey());
        });
    }

    /** Lève la suspension ; le statut de l'abonnement est recalculé d'après ses dates (voir SubscriptionStatus::afterSuspension). Idempotent. */
    public function reactivate(Tenant $tenant): void
    {
        if (! $tenant->isSuspended()) {
            return;
        }

        DB::transaction(function () use ($tenant) {
            $tenant->update(['status' => Tenant::STATUS_ACTIVE]);

            $subscription = $tenant->subscription()->lockForUpdate()->first();
            if ($subscription && $subscription->status === SubscriptionStatus::SUSPENDED) {
                $subscription->update(['status' => SubscriptionStatus::afterSuspension(
                    $subscription->current_period_end,
                    $subscription->grace_ends_at,
                    (bool) $subscription->cancel_at_period_end,
                    $subscription->trial_ends_at,
                    now(),
                )]);
            }

            $this->audit->log('tenant.reactivated', null, $tenant, ['subscription' => $subscription?->status], $tenant->getKey());
        });
    }

    /**
     * Tâche planifiée quotidienne, tous tenants confondus :
     *  TRIAL échu -> EXPIRED ; ACTIVE échu -> PAST_DUE (période de grâce) ou CANCELLED si résiliation demandée ;
     *  PAST_DUE dont la grâce est écoulée -> EXPIRED (ou CANCELLED si résiliation demandée).
     *
     * @return array{trial_expired:int, past_due:int, expired:int, cancelled:int}
     */
    public function process(?Carbon $now = null): array
    {
        $now ??= now();
        $counts = ['trial_expired' => 0, 'past_due' => 0, 'expired' => 0, 'cancelled' => 0];

        Subscription::withoutGlobalScopes()->with('plan')
            ->where('status', SubscriptionStatus::TRIAL)->where('trial_ends_at', '<=', $now)
            ->chunkById(200, function ($subscriptions) use (&$counts) {
                foreach ($subscriptions as $subscription) {
                    $subscription->update(['status' => SubscriptionStatus::EXPIRED]);
                    $this->audit->log('subscription.trial_expired', null, $subscription, [], $subscription->tenant_id);
                    $counts['trial_expired']++;
                }
            });

        Subscription::withoutGlobalScopes()->with('plan')
            ->where('status', SubscriptionStatus::ACTIVE)->where('current_period_end', '<=', $now)
            ->chunkById(200, function ($subscriptions) use (&$counts) {
                foreach ($subscriptions as $subscription) {
                    if ($subscription->cancel_at_period_end) {
                        $subscription->update(['status' => SubscriptionStatus::CANCELLED, 'cancelled_at' => now()]);
                        $this->audit->log('subscription.cancelled', null, $subscription, [], $subscription->tenant_id);
                        $counts['cancelled']++;

                        continue;
                    }

                    $grace = $subscription->current_period_end->copy()->addDays($subscription->plan->graceDays());
                    $subscription->update(['status' => SubscriptionStatus::PAST_DUE, 'grace_ends_at' => $grace]);
                    $this->audit->log('subscription.past_due', null, $subscription, ['grace_ends_at' => $grace->toIso8601String()], $subscription->tenant_id);
                    $counts['past_due']++;
                }
            });

        // Après la passe précédente : un abonnement resté longtemps sans traitement passe directement à EXPIRED.
        Subscription::withoutGlobalScopes()
            ->where('status', SubscriptionStatus::PAST_DUE)->where('grace_ends_at', '<=', $now)
            ->chunkById(200, function ($subscriptions) use (&$counts) {
                foreach ($subscriptions as $subscription) {
                    if ($subscription->cancel_at_period_end) {
                        $subscription->update(['status' => SubscriptionStatus::CANCELLED, 'cancelled_at' => now()]);
                        $this->audit->log('subscription.cancelled', null, $subscription, [], $subscription->tenant_id);
                        $counts['cancelled']++;

                        continue;
                    }

                    $subscription->update(['status' => SubscriptionStatus::EXPIRED]);
                    $this->audit->log('subscription.expired', null, $subscription, [], $subscription->tenant_id);
                    $counts['expired']++;
                }
            });

        return $counts;
    }
}
