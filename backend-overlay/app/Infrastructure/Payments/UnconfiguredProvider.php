<?php

namespace App\Infrastructure\Payments;

use App\Domain\Payments\Exceptions\ProviderNotConfiguredException;
use App\Domain\Payments\PaymentInitiation;
use App\Domain\Payments\PaymentProviderInterface;
use App\Domain\Payments\PaymentRequest;
use App\Domain\Payments\WebhookEvent;

/**
 * Base des adaptateurs PAS ENCORE branchés (Wave, Nita, Airtel Money, carte).
 * Volontairement vide : aucun endpoint ni format de webhook n'est supposé tant que la documentation
 * officielle et des identifiants de test ne sont pas disponibles. Voir docs/payment-adapter-guide.md.
 */
abstract class UnconfiguredProvider implements PaymentProviderInterface
{
    /** Variables d'environnement PROVISOIRES à confirmer d'après la documentation officielle du fournisseur. */
    abstract public function requiredEnv(): array;

    public function isConfigured(): bool
    {
        return false;
    }

    public function initiate(PaymentRequest $request): PaymentInitiation
    {
        throw new ProviderNotConfiguredException($this->code().' : adaptateur non branché (documentation officielle et identifiants requis).');
    }

    public function parseWebhook(string $rawBody, array $headers): WebhookEvent
    {
        throw new ProviderNotConfiguredException($this->code().' : adaptateur non branché.');
    }
}
