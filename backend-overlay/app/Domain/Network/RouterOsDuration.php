<?php

namespace App\Domain\Network;

/** Durées RouterOS : "1w2d3h4m5s" (unités optionnelles) ou "00:30:00". */
final class RouterOsDuration
{
    private const UNITS = ['w' => 604800, 'd' => 86400, 'h' => 3600, 'm' => 60, 's' => 1];

    public static function parse(mixed $text): int
    {
        if (! is_string($text) || $text === '') {
            return 0;
        }
        if (preg_match('/^(\d+):(\d{2}):(\d{2})$/D', $text, $m) === 1) {
            return (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3];
        }
        $total = 0;
        if (preg_match_all('/(\d+)([wdhms])/', $text, $all, PREG_SET_ORDER) > 0) {
            foreach ($all as [, $n, $unit]) {
                $total += (int) $n * self::UNITS[$unit];
            }
        }

        return $total;
    }

    /** Minutes -> "1h30m" (format accepté par limit-uptime). */
    public static function formatMinutes(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return ($h > 0 ? $h.'h' : '').($m > 0 || $h === 0 ? $m.'m' : '');
    }
}
