<?php

namespace App\Domain\Payments;

use App\Domain\Money\Money;

/** Événement fournisseur déjà authentifié (signature vérifiée par l'adaptateur) et normalisé. */
final class WebhookEvent
{
    public function __construct(
        public readonly string $eventId,
        public readonly string $providerReference,
        /** Statut cible : PaymentStatus::SUCCEEDED | FAILED | ... */
        public readonly string $status,
        public readonly ?Money $amount = null,
        public readonly ?string $failureReason = null,
    ) {}
}
