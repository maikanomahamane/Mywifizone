<?php

namespace App\Services;

use App\Domain\Payments\Exceptions\ProviderNotConfiguredException;
use App\Domain\Payments\PaymentProviderInterface;
use App\Domain\Payments\PaymentRequest;
use App\Domain\Payments\PaymentStatus;
use App\Domain\Payments\WebhookEvent as ProviderEvent;
use App\Domain\Tenancy\TenantContext;
use App\Infrastructure\Payments\PaymentManager;
use App\Models\Invoice;
use App\Models\PaymentProviderSetting;
use App\Models\PortalOrder;
use App\Models\PaymentTransaction;
use App\Models\WebhookEvent;
use App\Support\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class PaymentService
{
    public function __construct(
        private readonly PaymentManager $manager,
        private readonly InvoiceService $invoices,
        private readonly SubscriptionService $subscriptions,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
        private readonly PortalFulfillmentService $portal,
    ) {}

    /** Un fournisseur est utilisable s'il est activé par le Super Admin, branché (identifiants) et compatible avec la devise. */
    public function usableProviders(string $currency): array
    {
        $usable = [];

        foreach (PaymentProviderSetting::where('enabled', true)->get() as $setting) {
            $adapter = $this->manager->get($setting->code);

            if ($adapter && $adapter->isConfigured() && $setting->supportsCurrency($currency)) {
                $usable[] = ['code' => $setting->code, 'name' => $setting->name];
            }
        }

        return $usable;
    }

    public function assertUsable(string $code, string $currency): PaymentProviderInterface
    {
        $usable = array_column($this->usableProviders($currency), 'code');
        $adapter = $this->manager->get($code);

        if (! $adapter || ! in_array($code, $usable, true)) {
            throw new ApiException('provider_unavailable', 'Ce moyen de paiement n\'est pas disponible.', 422);
        }

        return $adapter;
    }

    public function findByIdempotencyKey(string $key): ?PaymentTransaction
    {
        return PaymentTransaction::with('invoice.plan')->where('idempotency_key', $key)->first();
    }

    /** Crée la transaction (INITIATED), appelle le fournisseur HORS transaction SQL, puis passe en PENDING. */
    public function start(Invoice $invoice, string $providerCode, string $idempotencyKey, ?string $phone): PaymentTransaction
    {
        if ($invoice->status !== Invoice::OPEN) {
            throw new ApiException('invoice_not_payable', 'Cette facture ne peut plus être payée.', 409);
        }

        $provider = $this->assertUsable($providerCode, $invoice->currency);

        $transaction = PaymentTransaction::create([
            'invoice_id' => $invoice->getKey(),
            'provider' => $providerCode,
            'idempotency_key' => $idempotencyKey,
            'amount' => $invoice->total,
            'currency' => $invoice->currency,
            'status' => PaymentStatus::INITIATED,
            'customer_phone' => $phone,
        ]);

        try {
            $initiation = $provider->initiate(new PaymentRequest(
                $transaction->getKey(),
                $transaction->money(),
                'Abonnement MY WIFIZONE '.$invoice->number,
                $phone,
            ));
        } catch (Throwable $e) { // ProviderNotConfiguredException, erreurs réseau d'un futur adaptateur réel...
            $transaction->update(['status' => PaymentStatus::FAILED, 'failure_reason' => mb_substr($e->getMessage(), 0, 250)]);
            $this->audit->log('payment.initiation_failed', null, $transaction, ['provider' => $providerCode]);

            throw new ApiException('provider_unavailable', 'Le fournisseur de paiement est indisponible, réessayez.', 503);
        }

        $transaction->update([
            'provider_reference' => $initiation->providerReference,
            'instructions' => $initiation->instructions,
            'status' => PaymentStatus::PENDING,
        ]);

        $this->audit->log('payment.initiated', null, $transaction, ['provider' => $providerCode, 'invoice' => $invoice->number]);

        return $transaction;
    }

    /**
     * Paiement d'une commande du portail captif (achat direct). Même cycle que l'abonnement : INITIATED -> appel fournisseur
     * hors transaction -> PENDING ; la livraison du voucher n'a lieu qu'au webhook SUCCEEDED vérifié.
     */
    public function startForOrder(PortalOrder $order, PaymentProviderInterface $provider, string $providerCode, ?string $phone): PaymentTransaction
    {
        $transaction = PaymentTransaction::create([
            'invoice_id' => null,
            'portal_order_id' => $order->getKey(),
            'provider' => $providerCode,
            'idempotency_key' => 'portal-'.$order->getKey(),
            'amount' => $order->amount,
            'currency' => $order->currency,
            'status' => PaymentStatus::INITIATED,
            'customer_phone' => $phone,
        ]);

        try {
            $initiation = $provider->initiate(new PaymentRequest($transaction->getKey(), $transaction->money(), 'Pass WiFi', $phone));
        } catch (Throwable $e) {
            $transaction->update(['status' => PaymentStatus::FAILED, 'failure_reason' => mb_substr($e->getMessage(), 0, 250)]);
            $this->audit->log('payment.initiation_failed', null, $transaction, ['provider' => $providerCode, 'portal_order' => $order->getKey()], $order->tenant_id);

            throw new ApiException('provider_unavailable', 'Le fournisseur de paiement est indisponible, réessayez.', 503);
        }

        $transaction->update(['provider_reference' => $initiation->providerReference, 'instructions' => $initiation->instructions, 'status' => PaymentStatus::PENDING]);

        return $transaction;
    }

    /**
     * Traite un webhook : signature vérifiée par l'adaptateur, journalisé (idempotence), puis appliqué
     * sous verrou dans une transaction SQL. Retourne : processed | duplicate | rejected | ignored.
     *
     * @param  array<string, string>  $headers  clés en minuscules
     *
     * @throws \App\Domain\Payments\Exceptions\InvalidWebhookException
     * @throws ProviderNotConfiguredException
     */
    public function handleWebhook(string $providerCode, string $rawBody, array $headers): string
    {
        $provider = $this->manager->get($providerCode)
            ?? throw new ProviderNotConfiguredException("Fournisseur inconnu : $providerCode");

        $event = $provider->parseWebhook($rawBody, $headers);

        // Insertion hors transaction : sur PostgreSQL une violation d'unicité abandonnerait la transaction en cours.
        $inserted = DB::table('webhook_events')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'provider' => $providerCode,
            'event_id' => $event->eventId,
            'payload' => $rawBody,
            'received_at' => now(),
        ]);

        if ($inserted === 0) {
            $existing = WebhookEvent::where('provider', $providerCode)->where('event_id', $event->eventId)->first();
            if ($existing?->processed_at) {
                return 'duplicate';
            }
            // Reçu mais jamais traité jusqu'au bout (erreur précédente) : on retraite.
        }

        return DB::transaction(function () use ($providerCode, $event) {
            $transaction = PaymentTransaction::withoutGlobalScopes()
                ->where('provider', $providerCode)
                ->where('provider_reference', $event->providerReference)
                ->lockForUpdate()
                ->first();

            $outcome = $transaction
                ? $this->context->run($transaction->tenant_id, fn () => $this->applyEvent($transaction, $event))
                : 'ignored';

            WebhookEvent::where('provider', $providerCode)->where('event_id', $event->eventId)
                ->update(['processed_at' => now(), 'outcome' => $outcome]);

            return $outcome;
        });
    }

    /**
     * Tâche planifiée : une transaction restée INITIATED/PENDING au-delà du délai est clôturée
     * (PENDING -> EXPIRED ; INITIATED -> FAILED, car la référence fournisseur n'a jamais été obtenue).
     * La facture reste OPEN : un paiement tardif confirmé ensuite par le fournisseur (EXPIRED -> SUCCEEDED) est honoré.
     * Interroger le fournisseur avant d'expirer demandera un adaptateur réel (API officielle) : à ajouter alors.
     *
     * @return array{expired:int, failed:int}
     */
    public function expireStale(): array
    {
        $counts = ['expired' => 0, 'failed' => 0];
        $limit = now()->subMinutes((int) config('payments.pending_timeout_minutes'));

        PaymentTransaction::withoutGlobalScopes()
            ->whereIn('status', [PaymentStatus::INITIATED, PaymentStatus::PENDING])
            ->where('created_at', '<=', $limit)
            ->chunkById(200, function ($candidates) use (&$counts) {
                foreach ($candidates as $candidate) {
                    DB::transaction(function () use ($candidate, &$counts) {
                        $transaction = PaymentTransaction::withoutGlobalScopes()->lockForUpdate()->find($candidate->getKey());

                        // Un webhook a pu régler la transaction entre-temps : on ne touche à rien.
                        $to = match ($transaction?->status) {
                            PaymentStatus::PENDING => PaymentStatus::EXPIRED,
                            PaymentStatus::INITIATED => PaymentStatus::FAILED,
                            default => null,
                        };

                        if ($to === null) {
                            return;
                        }

                        $transaction->update([
                            'status' => $to,
                            'failure_reason' => $to === PaymentStatus::EXPIRED ? 'payment_timeout' : 'initiation_timeout',
                            'completed_at' => now(),
                        ]);

                        $this->audit->log('payment.'.strtolower($to), null, $transaction, ['provider' => $transaction->provider, 'reason' => 'timeout'], $transaction->tenant_id);
                        if ($transaction->portal_order_id) {
                            $this->context->run($transaction->tenant_id, fn () => $this->portal->onNotPaid(PortalOrder::findOrFail($transaction->portal_order_id), $to));
                        } else {
                            $this->subscriptions->revertPendingIfIdle($transaction->tenant_id);
                        }

                        $counts[$to === PaymentStatus::EXPIRED ? 'expired' : 'failed']++;
                    });
                }
            });

        return $counts;
    }

    /** Appelé dans le contexte du tenant propriétaire de la transaction. */
    private function applyEvent(PaymentTransaction $transaction, ProviderEvent $event): string
    {
        $meta = ['provider' => $transaction->provider, 'event' => $event->eventId];

        // Un succès sans montant, ou avec un montant/devise différent, n'active jamais rien.
        if ($event->status === PaymentStatus::SUCCEEDED && ($event->amount === null || ! $event->amount->equals($transaction->money()))) {
            $this->audit->log('payment.amount_mismatch', null, $transaction, $meta + [
                'expected' => $transaction->amount.' '.$transaction->currency,
                'received' => $event->amount ? $event->amount->amount.' '.$event->amount->currency : 'absent',
            ], $transaction->tenant_id);

            return 'rejected';
        }

        if ($transaction->status === $event->status) {
            return 'duplicate';
        }

        if (! PaymentStatus::canTransition($transaction->status, $event->status)) {
            $this->audit->log('payment.invalid_transition', null, $transaction, $meta + ['from' => $transaction->status, 'to' => $event->status], $transaction->tenant_id);

            return 'ignored';
        }

        $transaction->update([
            'status' => $event->status,
            'failure_reason' => $event->failureReason,
            'completed_at' => now(),
        ]);

        $this->audit->log('payment.'.strtolower($event->status), null, $transaction, $meta, $transaction->tenant_id);

        if ($transaction->portal_order_id) {
            $order = PortalOrder::findOrFail($transaction->portal_order_id);
            if ($event->status === PaymentStatus::SUCCEEDED) {
                $this->portal->onPaid($order, $transaction);
            } elseif (in_array($event->status, [PaymentStatus::FAILED, PaymentStatus::CANCELLED, PaymentStatus::EXPIRED], true)) {
                $this->portal->onNotPaid($order, $event->status);
            }

            return 'processed';
        }

        if (in_array($event->status, [PaymentStatus::FAILED, PaymentStatus::CANCELLED, PaymentStatus::EXPIRED], true)) {
            $this->subscriptions->revertPendingIfIdle($transaction->tenant_id);
        }

        if ($event->status === PaymentStatus::SUCCEEDED) {
            $invoice = Invoice::findOrFail($transaction->invoice_id);

            if (! $this->invoices->markPaid($invoice, $transaction)) {
                // Facture déjà réglée par une autre transaction : l'argent a été encaissé deux fois, remboursement manuel à prévoir.
                $this->audit->log('payment.duplicate_success', null, $transaction, $meta + ['invoice' => $invoice->number], $transaction->tenant_id);
            }
        }

        return 'processed';
    }
}
