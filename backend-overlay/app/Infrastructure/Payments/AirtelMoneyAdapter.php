<?php

namespace App\Infrastructure\Payments;

final class AirtelMoneyAdapter extends UnconfiguredProvider
{
    public function code(): string
    {
        return 'airtel';
    }

    public function requiredEnv(): array
    {
        return ['AIRTEL_CLIENT_ID', 'AIRTEL_CLIENT_SECRET']; // provisoire
    }
}
