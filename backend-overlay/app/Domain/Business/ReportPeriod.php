<?php

namespace App\Domain\Business;

use Carbon\CarbonImmutable;

/** Regroupement d'un jour en seau de rapport (daily / weekly / monthly / annual). Calcul pur. */
final class ReportPeriod
{
    public const DAILY = 'daily';

    public const WEEKLY = 'weekly';

    public const MONTHLY = 'monthly';

    public const ANNUAL = 'annual';

    public const ALL = [self::DAILY, self::WEEKLY, self::MONTHLY, self::ANNUAL];

    /** Clé du seau : date du jour, lundi de la semaine ISO, premier jour du mois, premier jour de l'année. */
    public static function bucket(string $period, string $date): string
    {
        $d = CarbonImmutable::parse($date);

        return match ($period) {
            self::WEEKLY => $d->startOfWeek(CarbonImmutable::MONDAY)->toDateString(),
            self::MONTHLY => $d->startOfMonth()->toDateString(),
            self::ANNUAL => $d->startOfYear()->toDateString(),
            default => $d->toDateString(),
        };
    }

    /** Étendue maximale demandable, en jours (borne le volume de lignes agrégées). */
    public static function maxDays(string $period): int
    {
        return $period === self::DAILY ? 366 : 1830;
    }
}
