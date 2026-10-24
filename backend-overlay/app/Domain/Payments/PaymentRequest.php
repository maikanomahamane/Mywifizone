<?php

namespace App\Domain\Payments;

use App\Domain\Money\Money;

final class PaymentRequest
{
    public function __construct(
        public readonly string $transactionId,
        public readonly Money $amount,
        public readonly string $description,
        public readonly ?string $customerPhone = null,
    ) {}
}
