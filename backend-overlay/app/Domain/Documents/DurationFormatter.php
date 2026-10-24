<?php

namespace App\Domain\Documents;

/** Durée en minutes -> « 90 min », « 24 h », « 7 j » dans la langue du document. null = illimitée. */
final class DurationFormatter
{
    public static function format(?int $minutes, string $locale): string
    {
        if ($minutes === null || $minutes <= 0) {
            return DocumentLabels::get($locale, 'unlimited');
        }

        $min = DocumentLabels::get($locale, 'min');
        $hour = DocumentLabels::get($locale, 'hour');
        $day = DocumentLabels::get($locale, 'day');

        if ($minutes % 1440 === 0) {
            return intdiv($minutes, 1440).' '.$day;
        }
        if ($minutes % 60 === 0) {
            return intdiv($minutes, 60).' '.$hour;
        }
        if ($minutes > 60) {
            return intdiv($minutes, 60).' '.$hour.' '.($minutes % 60).' '.$min;
        }

        return $minutes.' '.$min;
    }
}
