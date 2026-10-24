<?php

namespace App\Domain\Money;

use InvalidArgumentException;

/**
 * Taux de change INDICATIF, en entiers : `scaled` = taux × 10^8 (1 unité majeure source = taux unités majeures cible).
 * Sert uniquement à afficher un équivalent. Il ne modifie jamais un prix, une vente ni un total enregistrés (cahier des charges §4).
 * Arithmétique entière sur chaînes : pas de float, pas de dépassement d'entier, pas de dépendance à bcmath.
 */
final class ExchangeRate
{
    public const SCALE = 8;

    public const MAX_SCALED = 1_000_000_000_000_000; // 10^15 : jusqu'à 9 999 999,99999999

    /** Taux fixes par traité (parité officielle) : 1 EUR = 655,957 XOF = 655,957 XAF ; 1 XOF = 1 XAF. */
    public const PEGS = [
        'EUR:XOF' => '655.957',
        'EUR:XAF' => '655.957',
        'XOF:EUR' => '0.00152449',
        'XAF:EUR' => '0.00152449',
        'XOF:XAF' => '1',
        'XAF:XOF' => '1',
    ];

    /** « 655.957 » -> 65595700000. Refuse zéro, signe, notation scientifique, plus de 8 décimales. */
    public static function parse(string $text): int
    {
        $text = trim($text);
        if (! preg_match('/^(\d{1,7})(?:\.(\d{1,8}))?$/', $text, $m)) {
            throw new InvalidArgumentException('rate_invalid');
        }
        $scaled = (int) ($m[1].str_pad($m[2] ?? '', self::SCALE, '0'));
        if ($scaled <= 0 || $scaled > self::MAX_SCALED) {
            throw new InvalidArgumentException('rate_invalid');
        }

        return $scaled;
    }

    /** 65595700000 -> « 655.957 » (zéros de fin retirés). */
    public static function format(int $scaled): string
    {
        $s = str_pad((string) $scaled, self::SCALE + 1, '0', STR_PAD_LEFT);
        $int = substr($s, 0, -self::SCALE);
        $dec = rtrim(substr($s, -self::SCALE), '0');

        return $dec === '' ? $int : $int.'.'.$dec;
    }

    /**
     * Convertit un montant en unités mineures de la devise source vers les unités mineures de la devise cible, arrondi à la demi-unité supérieure.
     */
    public static function convert(int $amountMinor, int $fromMinorUnit, int $toMinorUnit, int $scaled): int
    {
        if ($amountMinor < 0 || $scaled <= 0) {
            throw new InvalidArgumentException('Montant ou taux invalide.');
        }
        $product = self::multiply((string) $amountMinor, (string) $scaled);
        $exp = $toMinorUnit - $fromMinorUnit - self::SCALE;
        if ($exp >= 0) {
            $result = $product === '0' ? '0' : $product.str_repeat('0', $exp);
        } else {
            $result = self::divideByPowerOfTenRounded($product, -$exp);
        }
        if (strlen($result) > 18 || (int) $result < 0) {
            throw new InvalidArgumentException('rate_overflow');
        }

        return (int) $result;
    }

    /** Multiplication décimale en colonnes (entiers positifs en chaînes). */
    public static function multiply(string $a, string $b): string
    {
        if ($a === '0' || $b === '0') {
            return '0';
        }
        $la = strlen($a);
        $lb = strlen($b);
        $res = array_fill(0, $la + $lb, 0);
        for ($i = $la - 1; $i >= 0; $i--) {
            for ($j = $lb - 1; $j >= 0; $j--) {
                $sum = (int) $a[$i] * (int) $b[$j] + $res[$i + $j + 1];
                $res[$i + $j + 1] = $sum % 10;
                $res[$i + $j] += intdiv($sum, 10);
            }
        }

        return ltrim(implode('', $res), '0') ?: '0';
    }

    private static function divideByPowerOfTenRounded(string $n, int $k): string
    {
        if (strlen($n) <= $k) {
            // Valeur < 1 : arrondi à 1 si >= 0,5, sinon 0.
            $padded = str_pad($n, $k, '0', STR_PAD_LEFT);

            return $padded[0] >= '5' ? '1' : '0';
        }
        $head = substr($n, 0, -$k);
        $roundUp = $n[strlen($n) - $k] >= '5';
        if (! $roundUp) {
            return $head;
        }
        // head + 1 en chaîne
        $digits = str_split($head);
        for ($i = count($digits) - 1; $i >= 0; $i--) {
            if ($digits[$i] === '9') {
                $digits[$i] = '0';
            } else {
                $digits[$i] = (string) ((int) $digits[$i] + 1);

                return implode('', $digits);
            }
        }

        return '1'.implode('', $digits);
    }
}
