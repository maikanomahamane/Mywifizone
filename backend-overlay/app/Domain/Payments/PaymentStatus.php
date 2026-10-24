<?php

namespace App\Domain\Payments;

/**
 * Machine à états d'une transaction de paiement.
 *
 *   INITIATED ─► PENDING ─► SUCCEEDED ─► REFUNDED
 *       │           ├─────► FAILED
 *       │           ├─────► EXPIRED ─► SUCCEEDED   (le fournisseur fait foi : un paiement tardif est honoré)
 *       └───────────┴─────► CANCELLED
 *
 * INITIATED peut passer directement à SUCCEEDED/FAILED : un webhook peut arriver avant que
 * l'enregistrement de la référence fournisseur ne soit commité.
 * FAILED, CANCELLED et REFUNDED sont terminaux.
 */
final class PaymentStatus
{
    public const INITIATED = 'INITIATED';

    public const PENDING = 'PENDING';

    public const SUCCEEDED = 'SUCCEEDED';

    public const FAILED = 'FAILED';

    public const EXPIRED = 'EXPIRED';

    public const CANCELLED = 'CANCELLED';

    public const REFUNDED = 'REFUNDED';

    private const TRANSITIONS = [
        self::INITIATED => [self::PENDING, self::SUCCEEDED, self::FAILED, self::CANCELLED],
        self::PENDING => [self::SUCCEEDED, self::FAILED, self::EXPIRED, self::CANCELLED],
        self::EXPIRED => [self::SUCCEEDED],
        self::SUCCEEDED => [self::REFUNDED],
        self::FAILED => [],
        self::CANCELLED => [],
        self::REFUNDED => [],
    ];

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function isTerminal(string $status): bool
    {
        return (self::TRANSITIONS[$status] ?? []) === [];
    }
}
