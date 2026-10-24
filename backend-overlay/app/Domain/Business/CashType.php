<?php

namespace App\Domain\Business;

final class CashType
{
    public const OPEN = 'OPEN';

    public const CASH_IN = 'CASH_IN';

    public const SALE = 'SALE';

    public const EXPENSE = 'EXPENSE';

    public const WITHDRAWAL = 'WITHDRAWAL';

    public const ADJUSTMENT = 'ADJUSTMENT';

    public const CLOSE = 'CLOSE';

    public const ALL = [self::OPEN, self::CASH_IN, self::SALE, self::EXPENSE, self::WITHDRAWAL, self::ADJUSTMENT, self::CLOSE];

    /** Signe imposé : les montants saisis sont positifs, la nature de l'opération décide du sens. ADJUSTMENT est signé librement. */
    public static function signed(string $type, int $amount): int
    {
        return match ($type) {
            self::EXPENSE, self::WITHDRAWAL => -abs($amount),
            self::OPEN, self::CASH_IN, self::SALE => abs($amount),
            default => $amount,
        };
    }
}
