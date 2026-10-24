<?php

namespace App\Infrastructure\Payments;

use App\Domain\Money\Money;
use App\Domain\Payments\Exceptions\InvalidWebhookException;
use App\Domain\Payments\Exceptions\ProviderNotConfiguredException;
use App\Domain\Payments\PaymentInitiation;
use App\Domain\Payments\PaymentProviderInterface;
use App\Domain\Payments\PaymentRequest;
use App\Domain\Payments\PaymentStatus;
use App\Domain\Payments\Signature\HmacSignatureVerifier;
use App\Domain\Payments\WebhookEvent;
use InvalidArgumentException;

/**
 * Fournisseur FICTIF pour le développement et les tests : ce n'est l'API d'aucun opérateur réel.
 * Webhook : POST JSON {event_id, type: payment.succeeded|payment.failed, reference, amount, currency, reason?}
 * avec les en-têtes X-Mock-Timestamp et X-Mock-Signature (HMAC-SHA256 de "{timestamp}.{corps}").
 * Désactivé par défaut en production (PAYMENT_MOCK_ENABLED) et inutilisable sans secret.
 */
final class MockPaymentProvider implements PaymentProviderInterface
{
    public function __construct(
        private readonly string $secret,
        private readonly bool $enabled,
        private readonly HmacSignatureVerifier $verifier,
    ) {}

    public function code(): string
    {
        return 'mock';
    }

    public function isConfigured(): bool
    {
        return $this->enabled && $this->secret !== '';
    }

    public function initiate(PaymentRequest $request): PaymentInitiation
    {
        $this->assertConfigured();

        return new PaymentInitiation('mock_'.$request->transactionId, [
            'message' => 'Fournisseur de test : envoyez un webhook signé pour confirmer ou refuser ce paiement.',
        ]);
    }

    public function parseWebhook(string $rawBody, array $headers): WebhookEvent
    {
        $this->assertConfigured();

        $timestamp = $headers['x-mock-timestamp'] ?? '';
        $signature = $headers['x-mock-signature'] ?? '';

        if (! $this->verifier->verify($this->secret, $timestamp, $signature, $rawBody)) {
            throw new InvalidWebhookException('Signature invalide ou horodatage périmé.');
        }

        $data = json_decode($rawBody, true);

        if (! is_array($data) || ! is_string($data['event_id'] ?? null) || ! is_string($data['reference'] ?? null)) {
            throw new InvalidWebhookException('Corps de webhook illisible.');
        }

        $status = match ($data['type'] ?? null) {
            'payment.succeeded' => PaymentStatus::SUCCEEDED,
            'payment.failed' => PaymentStatus::FAILED,
            default => throw new InvalidWebhookException('Type d\'événement non pris en charge.'),
        };

        $amount = null;
        if (array_key_exists('amount', $data) || array_key_exists('currency', $data)) {
            try {
                if (! is_int($data['amount'] ?? null) || ! is_string($data['currency'] ?? null)) {
                    throw new InvalidArgumentException('montant/devise');
                }
                $amount = Money::of($data['amount'], $data['currency']);
            } catch (InvalidArgumentException) {
                throw new InvalidWebhookException('Montant ou devise invalide.');
            }
        }

        return new WebhookEvent(
            eventId: $data['event_id'],
            providerReference: $data['reference'],
            status: $status,
            amount: $amount,
            failureReason: is_string($data['reason'] ?? null) ? $data['reason'] : null,
        );
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new ProviderNotConfiguredException('Le fournisseur mock est désactivé.');
        }
    }
}
