<?php

namespace App\Services;

use App\Domain\Money\ExchangeRate as Rate;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Support\ApiException;
use InvalidArgumentException;

/** Devises actives et conversion indicative. Aucun appel externe : les taux sont saisis par le propriétaire ou fixés par traité (XOF/XAF/EUR). */
class CurrencyService
{
    /** @return array{amount: int, currency: string, rate: string, source: string, indicative: true} */
    public function convert(int $amountMinor, string $from, string $to): array
    {
        $from = strtoupper($from);
        $to = strtoupper($to);
        $fromC = $this->activeOrFail($from);
        $toC = $this->activeOrFail($to);

        if ($from === $to) {
            return ['amount' => $amountMinor, 'currency' => $to, 'rate' => '1', 'source' => 'identity', 'indicative' => true];
        }
        $peg = Rate::PEGS["$from:$to"] ?? null;
        if ($peg !== null) {
            $scaled = Rate::parse($peg);
            $source = 'peg';
        } else {
            $row = ExchangeRate::query()->where('from_currency', $from)->where('to_currency', $to)->first();
            if (! $row) {
                throw new ApiException('rate_unavailable', "Aucun taux $from → $to.", 422, ['from' => $from, 'to' => $to]);
            }
            $scaled = $row->rate_scaled;
            $source = 'manual';
        }
        try {
            $amount = Rate::convert($amountMinor, $fromC->minor_unit, $toC->minor_unit, $scaled);
        } catch (InvalidArgumentException) {
            throw new ApiException('rate_overflow', 'Montant trop grand pour la conversion.', 422);
        }

        return ['amount' => $amount, 'currency' => $to, 'rate' => Rate::format($scaled), 'source' => $source, 'indicative' => true];
    }

    public function activeOrFail(string $code): Currency
    {
        $c = Currency::query()->where('is_active', true)->find($code);
        if (! $c) {
            throw new ApiException('currency_unavailable', "Devise $code inconnue ou désactivée.", 422, ['currency' => $code]);
        }

        return $c;
    }
}
