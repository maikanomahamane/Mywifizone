<?php

namespace App\Domain\Portal;

/**
 * PENDING_PAYMENT ─► FULFILLED          (paiement confirmé par webhook vérifié + voucher livré)
 *        │       ─► NEEDS_ATTENTION     (paiement confirmé mais plus aucun voucher : remboursement/livraison manuelle)
 *        │       ─► FAILED | EXPIRED    (EXPIRED peut encore être honoré si le fournisseur confirme tard)
 */
final class PortalOrderStatus
{
    public const PENDING_PAYMENT = 'PENDING_PAYMENT';

    public const FULFILLED = 'FULFILLED';

    public const NEEDS_ATTENTION = 'NEEDS_ATTENTION';

    public const FAILED = 'FAILED';

    public const EXPIRED = 'EXPIRED';

    public const ALL = [self::PENDING_PAYMENT, self::FULFILLED, self::NEEDS_ATTENTION, self::FAILED, self::EXPIRED];

    /** Statuts depuis lesquels un paiement confirmé doit livrer le voucher. */
    public static function canFulfill(string $status): bool
    {
        return in_array($status, [self::PENDING_PAYMENT, self::EXPIRED], true);
    }
}
