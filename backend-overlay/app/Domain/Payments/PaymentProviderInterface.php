<?php

namespace App\Domain\Payments;

use App\Domain\Payments\Exceptions\InvalidWebhookException;
use App\Domain\Payments\Exceptions\ProviderNotConfiguredException;

/**
 * Contrat d'un fournisseur de paiement (Mobile Money, carte...).
 * Règle du projet : aucune implémentation réelle sans documentation officielle ET identifiants de test.
 */
interface PaymentProviderInterface
{
    /** Code stable : mock, wave, nita, airtel, card... */
    public function code(): string;

    /** Vrai si les identifiants nécessaires sont présents (jamais vrai pour un adaptateur non branché). */
    public function isConfigured(): bool;

    /** @throws ProviderNotConfiguredException */
    public function initiate(PaymentRequest $request): PaymentInitiation;

    /**
     * Authentifie et normalise un webhook. $headers : clés en minuscules, valeurs en chaîne.
     *
     * @param  array<string, string>  $headers
     *
     * @throws InvalidWebhookException signature absente/fausse, horodatage périmé, corps illisible
     * @throws ProviderNotConfiguredException
     */
    public function parseWebhook(string $rawBody, array $headers): WebhookEvent;
}
