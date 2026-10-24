<?php

namespace App\Domain\Network;

/**
 * Cycle de vie d'un voucher.
 *
 *   GENERATED ─► AVAILABLE ─► SOLD ─► ACTIVE ─► USED
 *       │            │         │        │
 *       ├──► VIP ────┼─────────┴────────┤       (VIP : « prêt à l'emploi » d'un voucher de profil VIP)
 *       └────────────┴──► EXPIRED / REVOKED  (états terminaux, avec USED)
 *
 * GENERATED : créé en base, pas encore confirmé par le routeur.
 * AVAILABLE : présent sur le HotSpot, prêt à vendre ; VIP : idem pour un profil VIP.
 * ACTIVE    : une session a été observée sur le routeur.
 * USED      : limites épuisées (durée/volume), signalé par le routeur.
 */
final class VoucherStatus
{
    public const GENERATED = 'GENERATED';

    public const AVAILABLE = 'AVAILABLE';

    public const SOLD = 'SOLD';

    public const ACTIVE = 'ACTIVE';

    public const USED = 'USED';

    public const EXPIRED = 'EXPIRED';

    public const REVOKED = 'REVOKED';

    public const VIP = 'VIP';

    public const ALL = [self::GENERATED, self::AVAILABLE, self::SOLD, self::ACTIVE, self::USED, self::EXPIRED, self::REVOKED, self::VIP];

    /** États dans lesquels un voucher peut encore être utilisé par un client. */
    public const LIVE = [self::GENERATED, self::AVAILABLE, self::SOLD, self::VIP, self::ACTIVE];

    private const TRANSITIONS = [
        // USED est atteignable depuis tout état « vivant » : le voucher peut être consommé entre deux relevés du routeur.
        self::GENERATED => [self::AVAILABLE, self::VIP, self::ACTIVE, self::USED, self::EXPIRED, self::REVOKED],
        self::AVAILABLE => [self::SOLD, self::ACTIVE, self::USED, self::EXPIRED, self::REVOKED],
        self::SOLD => [self::ACTIVE, self::USED, self::EXPIRED, self::REVOKED],
        self::VIP => [self::ACTIVE, self::USED, self::EXPIRED, self::REVOKED],
        self::ACTIVE => [self::USED, self::EXPIRED, self::REVOKED],
        self::USED => [],
        self::EXPIRED => [],
        self::REVOKED => [],
    ];

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function isTerminal(string $status): bool
    {
        return (self::TRANSITIONS[$status] ?? [null]) === [];
    }
}
