<?php

namespace App\Infrastructure\Payments;

final class WaveAdapter extends UnconfiguredProvider
{
    public function code(): string
    {
        return 'wave';
    }

    public function requiredEnv(): array
    {
        return ['WAVE_API_KEY', 'WAVE_WEBHOOK_SECRET']; // provisoire
    }
}
