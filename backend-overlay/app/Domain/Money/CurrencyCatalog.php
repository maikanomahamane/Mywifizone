<?php

namespace App\Domain\Money;

/** Devises initiales (cahier des charges §4) : code => [nom, unités mineures, symbole]. Utilisé par le seeder ; la table `currencies` fait foi ensuite. */
final class CurrencyCatalog
{
    public const DEFAULTS = [
        'XOF' => ['Franc CFA (BCEAO)', 0, 'FCFA'],
        'XAF' => ['Franc CFA (BEAC)', 0, 'FCFA'],
        'NGN' => ['Naira nigérian', 2, '₦'],
        'GHS' => ['Cedi ghanéen', 2, 'GH₵'],
        'KES' => ['Shilling kényan', 2, 'KSh'],
        'USD' => ['Dollar américain', 2, '$'],
        'EUR' => ['Euro', 2, '€'],
        'GBP' => ['Livre sterling', 2, '£'],
    ];
}
