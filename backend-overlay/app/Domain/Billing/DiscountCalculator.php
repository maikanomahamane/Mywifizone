<?php

namespace App\Domain\Billing;

use App\Domain\Money\Money;
use InvalidArgumentException;

final class DiscountCalculator
{
    /**
     * Remise en unités mineures, plafonnée au prix (le total ne peut pas être négatif).
     * FIXED : `$value` est exprimé dans la devise du prix (le service vérifie la devise de la promotion).
     */
    public static function discount(Money $price, string $type, int $value): Money
    {
        return match ($type) {
            PromotionType::PERCENTAGE => ($value >= 1 && $value <= 100)
                ? $price->percentOf($value)
                : throw new InvalidArgumentException('Pourcentage de remise hors bornes (1-100).'),
            PromotionType::FIXED => $value >= 0
                ? Money::of(min($value, $price->amount), $price->currency)
                : throw new InvalidArgumentException('Remise fixe négative.'),
            default => throw new InvalidArgumentException("Ce type de promotion n'agit pas sur le prix : $type"),
        };
    }
}
