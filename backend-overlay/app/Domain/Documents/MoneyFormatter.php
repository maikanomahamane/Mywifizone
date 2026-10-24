<?php

namespace App\Domain\Documents;

/** Montant en unités mineures -> texte imprimable (« 12 500 FCFA », « 10.50 $ »). Espaces insécables : le montant ne se coupe pas. */
final class MoneyFormatter
{
    private const NBSP = "\u{00A0}";

    public static function format(int $amount, int $decimals, string $symbol): string
    {
        $negative = $amount < 0;
        $abs = abs($amount);
        $decimals = max(0, min($decimals, 4));
        $factor = 10 ** $decimals;

        $major = intdiv($abs, $factor);
        $text = self::group($major);
        if ($decimals > 0) {
            $text .= '.'.str_pad((string) ($abs % $factor), $decimals, '0', STR_PAD_LEFT);
        }

        return ($negative ? '-' : '').$text.self::NBSP.$symbol;
    }

    private static function group(int $n): string
    {
        return (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', self::NBSP, (string) $n);
    }
}
