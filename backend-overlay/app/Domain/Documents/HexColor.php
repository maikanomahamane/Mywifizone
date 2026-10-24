<?php

namespace App\Domain\Documents;

/** Couleur de marque : uniquement #RRGGBB (jamais de texte libre injecté dans le CSS du PDF). */
final class HexColor
{
    public const DEFAULT = '#EF3340';

    public static function isValid(?string $color): bool
    {
        return is_string($color) && preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1;
    }

    public static function normalize(?string $color): string
    {
        return self::isValid($color) ? strtoupper($color) : self::DEFAULT;
    }

    /** Noir ou blanc, selon la luminance relative (WCAG) du fond, pour un texte lisible sur la couleur de marque. */
    public static function contrastText(string $background): string
    {
        $hex = ltrim(self::normalize($background), '#');
        $lin = array_map(function (string $pair): float {
            $c = hexdec($pair) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, str_split($hex, 2));
        $luminance = 0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2];

        return $luminance > 0.179 ? '#000000' : '#FFFFFF';
    }
}
