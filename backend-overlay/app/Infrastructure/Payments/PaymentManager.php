<?php

namespace App\Infrastructure\Payments;

use App\Domain\Payments\PaymentProviderInterface;

/** Registre des adaptateurs, par code. Ajouter un fournisseur = une classe + une ligne dans AppServiceProvider. */
final class PaymentManager
{
    /** @param array<string, PaymentProviderInterface> $providers */
    public function __construct(private readonly array $providers) {}

    public function get(string $code): ?PaymentProviderInterface
    {
        return $this->providers[$code] ?? null;
    }

    /** @return array<string, PaymentProviderInterface> */
    public function all(): array
    {
        return $this->providers;
    }
}
