<?php

namespace App\Domain\Network;

final class ProfileKind
{
    public const STANDARD = 'STANDARD';

    /** Profil VIP : gratuit ou payant, durée/volume éventuellement illimités. */
    public const VIP = 'VIP';

    public const ALL = [self::STANDARD, self::VIP];
}
