<?php

namespace App\Domain\Network;

/**
 * PENDING  : en file, en attente d'être livrée à l'agent ;
 * LEASED   : livrée à l'agent, résultat attendu avant `leased_until` (sinon elle est remise en file) ;
 * SUCCEEDED / FAILED : terminales (FAILED après épuisement des tentatives ou expiration).
 */
final class CommandStatus
{
    public const PENDING = 'PENDING';

    public const LEASED = 'LEASED';

    public const SUCCEEDED = 'SUCCEEDED';

    public const FAILED = 'FAILED';

    public const ALL = [self::PENDING, self::LEASED, self::SUCCEEDED, self::FAILED];

    public static function isTerminal(string $status): bool
    {
        return in_array($status, [self::SUCCEEDED, self::FAILED], true);
    }
}
