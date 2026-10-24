<?php

namespace App\Domain\Documents;

/** Un ticket prêt à imprimer. Aucune requête, aucun modèle : de simples valeurs. */
final class TicketData
{
    public function __construct(
        public readonly string $code,
        public readonly string $planName,
        public readonly string $priceText,
        public readonly ?int $durationMinutes,
        public readonly ?string $validUntilText,
        public readonly ?int $deviceLimit,
        public readonly ?string $rateLimit,
        public readonly bool $isVip,
        public readonly string $qrPayload,
    ) {}
}
