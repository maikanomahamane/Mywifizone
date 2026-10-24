<?php

namespace App\Domain\Billing;

final class PromotionType
{
    public const PERCENTAGE = 'PERCENTAGE';

    public const FIXED = 'FIXED';

    /** Prolonge l'essai de `value` jours (utilisable à l'inscription). */
    public const TRIAL_EXTENSION = 'TRIAL_EXTENSION';

    public const ALL = [self::PERCENTAGE, self::FIXED, self::TRIAL_EXTENSION];
}
