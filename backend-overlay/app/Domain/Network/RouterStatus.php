<?php

namespace App\Domain\Network;

final class RouterStatus
{
    /** Jamais vu : l'agent n'est pas encore enrôlé ou n'a encore envoyé aucun signal. */
    public const PENDING = 'PENDING';

    public const ONLINE = 'ONLINE';

    public const OFFLINE = 'OFFLINE';

    public const ALL = [self::PENDING, self::ONLINE, self::OFFLINE];
}
