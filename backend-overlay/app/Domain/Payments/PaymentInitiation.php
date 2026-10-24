<?php

namespace App\Domain\Payments;

final class PaymentInitiation
{
    /** @param array<string, mixed> $instructions ce que l'UI doit montrer au payeur (lien de checkout, code USSD...) */
    public function __construct(
        public readonly string $providerReference,
        public readonly array $instructions = [],
    ) {}
}
