<?php

namespace App\Infrastructure\Payments;

/** Carte Visa/Mastercard via le checkout hébergé d'un prestataire (aucune donnée de carte ne transite chez nous). */
final class CardAdapter extends UnconfiguredProvider
{
    public function code(): string
    {
        return 'card';
    }

    public function requiredEnv(): array
    {
        return ['CARD_PROVIDER_API_KEY', 'CARD_PROVIDER_WEBHOOK_SECRET']; // provisoire
    }
}
