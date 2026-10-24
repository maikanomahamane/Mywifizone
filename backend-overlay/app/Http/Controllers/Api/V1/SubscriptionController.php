<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Site;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\AuditLogger;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Services\SubscriptionService;
use App\Support\ApiException;
use App\Support\ApiResponse;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly InvoiceService $invoices,
        private readonly PaymentService $payments,
        private readonly AuditLogger $audit,
    ) {}

    public function show(): JsonResponse
    {
        $subscription = Subscription::with('plan')->first();

        return ApiResponse::ok([
            'subscription' => $subscription?->toPayload(),
            'plan' => $subscription?->plan?->toPublicArray(),
            'usage' => ['sites' => Site::count()],
        ]);
    }

    /** Moyens de paiement réellement utilisables (activés par le Super Admin ET branchés). */
    public function providers(Request $request): JsonResponse
    {
        $currency = strtoupper(substr((string) $request->query('currency', 'XOF'), 0, 3));

        return ApiResponse::ok($this->payments->usableProviders($currency));
    }

    public function checkout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan_code' => ['required', 'string', Rule::exists('subscription_plans', 'code')->where('is_active', true)],
            'billing_cycle' => ['required', Rule::in([SubscriptionPlan::MONTHLY, SubscriptionPlan::YEARLY])],
            'provider' => ['required', 'string', 'max:32'],
            'promo_code' => ['nullable', 'string', 'max:40'],
            'phone' => ['nullable', 'regex:/^\+?\d{8,15}$/'],
        ]);

        $key = (string) $request->header('Idempotency-Key', '');
        if ($key === '') {
            $key = (string) Str::uuid();
        } elseif (! preg_match('/^[A-Za-z0-9_\-:.]{1,100}$/', $key)) {
            throw new ApiException('invalid_idempotency_key', 'En-tête Idempotency-Key invalide.', 422);
        }

        // Rejeu de la même requête (double tap, réseau instable) : même résultat, aucune nouvelle facture.
        if ($existing = $this->payments->findByIdempotencyKey($key)) {
            return $this->replay($existing, $data);
        }

        $plan = SubscriptionPlan::where('code', $data['plan_code'])->firstOrFail();
        $subscription = Subscription::first()
            ?? throw new ApiException('no_subscription', 'Aucun abonnement pour ce compte.', 409);
        $promotion = ! empty($data['promo_code'])
            ? $this->subscriptions->findPromotion($data['promo_code'], $plan->code, false)
            : null;

        $invoice = DB::transaction(function () use ($subscription, $plan, $data, $promotion) {
            $invoice = $this->invoices->createForCheckout($subscription, $plan, $data['billing_cycle'], $promotion);
            $this->subscriptions->markPendingIfInactive($subscription);

            return $invoice;
        });

        $this->audit->log('subscription.checkout_started', null, $invoice, ['plan' => $plan->code, 'cycle' => $data['billing_cycle']]);

        // Totalité remise par une promotion : pas de paiement, la facture est soldée immédiatement.
        if ($invoice->total === 0) {
            DB::transaction(fn () => $this->invoices->markPaid($invoice, null));

            return ApiResponse::ok(['invoice' => $invoice->fresh('plan')->toPayload(), 'payment' => null], status: 201);
        }

        try {
            $transaction = $this->payments->start($invoice, $data['provider'], $key, $data['phone'] ?? null);
        } catch (UniqueConstraintViolationException) {
            // Même Idempotency-Key reçue en parallèle : l'autre requête a gagné, on rejoue son résultat.
            $this->invoices->void($invoice);
            $winner = $this->payments->findByIdempotencyKey($key)
                ?? throw new ApiException('idempotency_conflict', 'Requête concurrente, réessayez.', 409);

            return $this->replay($winner, $data);
        } catch (ApiException $e) {
            $this->invoices->void($invoice); // pas de facture orpheline ouverte
            $this->subscriptions->revertPendingIfIdle($subscription->tenant_id); // ne pas rester « en attente » sans paiement

            throw $e;
        }

        return ApiResponse::ok(['invoice' => $invoice->fresh('plan')->toPayload(), 'payment' => $transaction->toPayload()], status: 201);
    }

    public function cancel(): JsonResponse
    {
        $subscription = Subscription::with('plan')->firstOrFail();

        return ApiResponse::ok($this->subscriptions->cancel($subscription)->toPayload());
    }

    /** @param array<string, mixed> $data requête validée, à comparer à celle qui a créé la transaction */
    private function replay(\App\Models\PaymentTransaction $existing, array $data): JsonResponse
    {
        $same = $existing->invoice->plan->code === $data['plan_code']
            && $existing->invoice->billing_cycle === $data['billing_cycle']
            && $existing->provider === $data['provider'];

        if (! $same) {
            throw new ApiException('idempotency_key_reused', 'Cette clé d\'idempotence a déjà servi pour une autre demande.', 409);
        }

        return ApiResponse::ok(['invoice' => $existing->invoice->toPayload(), 'payment' => $existing->toPayload()]);
    }
}
