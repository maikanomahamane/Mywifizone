<?php

namespace App\Infrastructure\Payments;

final class NitaAdapter extends UnconfiguredProvider
{
    public function code(): string
    {
        return 'nita';
    }

    public function requiredEnv(): array
    {
        return ['NITA_API_KEY', 'NITA_WEBHOOK_SECRET']; // provisoire
    }
}
