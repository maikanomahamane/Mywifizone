<?php

namespace App\Domain\Documents;

use InvalidArgumentException;

/** Géométrie des feuilles de tickets (millimètres). Calcul pur : les invariants sont testés sans moteur PDF. */
final class TicketLayout
{
    public const FORMATS = ['a4', 'a5', 'individual', 'thermal'];

    /**
     * @return array{format: string, page_w: int, page_h: int, margin: int, cols: int, rows: int, card_w: float, card_h: int, per_page: int, qr_size: float}
     */
    public static function for(string $format, int $thermalWidth = 58): array
    {
        [$w, $h, $m, $cols, $cardH, $qr] = match ($format) {
            'a4' => [210, 297, 8, 3, 38, 1.1],
            'a5' => [148, 210, 6, 2, 42, 1.2],
            'individual' => [100, 62, 3, 1, 56, 1.2],
            'thermal' => $thermalWidth === 80 ? [80, 100, 2, 1, 96, 1.3] : [58, 95, 2, 1, 91, 1.0],
            default => throw new InvalidArgumentException("Format inconnu : $format"),
        };

        $rows = max(1, intdiv($h - 2 * $m, $cardH));

        return [
            'format' => $format,
            'page_w' => $w,
            'page_h' => $h,
            'margin' => $m,
            'cols' => $cols,
            'rows' => $rows,
            'card_w' => ($w - 2 * $m) / $cols,
            'card_h' => $cardH,
            'per_page' => $cols * $rows,
            'qr_size' => $qr,
        ];
    }
}
