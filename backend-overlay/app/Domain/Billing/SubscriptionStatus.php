<?php

namespace App\Domain\Billing;

use DateTimeInterface;

final class SubscriptionStatus
{
    public const TRIAL = 'TRIAL';

    public const ACTIVE = 'ACTIVE';

    /** En attente d'un premier paiement (checkout lancé depuis EXPIRED/CANCELLED). */
    public const PENDING = 'PENDING';

    /** Période échue, en période de grâce : l'accès est conservé. */
    public const PAST_DUE = 'PAST_DUE';

    public const EXPIRED = 'EXPIRED';

    /** Suspendu par le Super Admin : un paiement ne le réactive pas. */
    public const SUSPENDED = 'SUSPENDED';

    public const CANCELLED = 'CANCELLED';

    /** Statuts qui autorisent les écritures métier (les lectures restent permises pour pouvoir payer). */
    public const WRITABLE = [self::TRIAL, self::ACTIVE, self::PAST_DUE];

    /**
     * Statut à restaurer quand le Super Admin lève une suspension, déduit des dates.
     * Le temps passé suspendu COMPTE : ni l'essai ni la période payée ne sont prolongés (hypothèse à valider).
     * `process()` ignore les abonnements SUSPENDED, d'où ce recalcul à la réactivation.
     */
    public static function afterSuspension(
        ?DateTimeInterface $periodEnd,
        ?DateTimeInterface $graceEndsAt,
        bool $cancelAtPeriodEnd,
        ?DateTimeInterface $trialEndsAt,
        DateTimeInterface $now,
    ): string {
        if ($periodEnd !== null) {
            return match (true) {
                $periodEnd > $now => self::ACTIVE,
                $cancelAtPeriodEnd => self::CANCELLED,
                $graceEndsAt !== null && $graceEndsAt > $now => self::PAST_DUE,
                default => self::EXPIRED,
            };
        }

        return $trialEndsAt !== null && $trialEndsAt > $now ? self::TRIAL : self::EXPIRED;
    }
}
