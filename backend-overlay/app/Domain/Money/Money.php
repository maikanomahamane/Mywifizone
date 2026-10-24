<?php

namespace App\Domain\Money;

use InvalidArgumentException;

/**
 * Montant en unités mineures (entiers) + devise ISO 4217. Jamais de float.
 * XOF/XAF n'ont pas de décimales : 2000 FCFA = Money::of(2000, 'XOF').
 * La devise n'est validée que par son format : ajouter une devise ne demande aucune modification de code.
 */
final class Money
{
    private function __construct(
        public readonly int $amount,
        public readonly string $currency,
    ) {}

    public static function of(int $amount, string $currency): self
    {
        $currency = strtoupper($currency);

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException("Code devise invalide : $currency");
        }
        if ($amount < 0) {
            throw new InvalidArgumentException('Montant négatif interdit.');
        }

        return new self($amount, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        if ($other->amount > $this->amount) {
            throw new InvalidArgumentException('Résultat négatif interdit.');
        }

        return new self($this->amount - $other->amount, $this->currency);
    }

    /** Pourcentage entier, arrondi à l'unité inférieure (une remise calculée ainsi n'est jamais arrondie en faveur du client). */
    public function percentOf(int $percent): self
    {
        if ($percent < 0 || $percent > 100) {
            throw new InvalidArgumentException('Pourcentage hors bornes.');
        }

        return new self(intdiv($this->amount * $percent, 100), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException("Devises différentes : {$this->currency} / {$other->currency}");
        }
    }
}
