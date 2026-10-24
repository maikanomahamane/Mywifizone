<?php

namespace App\Domain\Business;

/**
 * Moyens d'encaissement déclarés par le vendeur. Aucun n'est vérifié auprès d'un opérateur : la référence de
 * transaction est une saisie libre. Les paiements vérifiés passeront par PaymentProviderInterface (Phase 7).
 */
final class PaymentMethod
{
    public const CASH = 'CASH';

    public const MOBILE_MONEY = 'MOBILE_MONEY';

    public const BANK = 'BANK';

    public const OTHER = 'OTHER';

    public const ALL = [self::CASH, self::MOBILE_MONEY, self::BANK, self::OTHER];
}
