<?php

namespace App\Domain\Network;

/** Types de commandes que le cloud peut envoyer à l'agent d'un routeur. */
final class CommandType
{
    public const CREATE_VOUCHER = 'CREATE_VOUCHER';

    public const DISABLE_VOUCHER = 'DISABLE_VOUCHER';

    public const REVOKE_VOUCHER = 'REVOKE_VOUCHER';

    public const FETCH_SESSIONS = 'FETCH_SESSIONS';

    public const DISCONNECT_USER = 'DISCONNECT_USER';

    public const FETCH_HEALTH = 'FETCH_HEALTH';

    public const SYNC_PROFILES = 'SYNC_PROFILES';

    public const ALL = [
        self::CREATE_VOUCHER, self::DISABLE_VOUCHER, self::REVOKE_VOUCHER, self::FETCH_SESSIONS,
        self::DISCONNECT_USER, self::FETCH_HEALTH, self::SYNC_PROFILES,
    ];
}
