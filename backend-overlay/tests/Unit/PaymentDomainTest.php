<?php

namespace Tests\Unit;

use App\Domain\Billing\DiscountCalculator;
use App\Domain\Billing\PromotionType;
use App\Domain\Money\Money;
use App\Domain\Payments\Exceptions\InvalidWebhookException;
use App\Domain\Payments\PaymentStatus;
use App\Domain\Payments\Signature\HmacSignatureVerifier;
use App\Infrastructure\Payments\MockPaymentProvider;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PaymentDomainTest extends TestCase
{
    public function test_state_machine_allows_only_declared_transitions(): void
    {
        $this->assertTrue(PaymentStatus::canTransition('PENDING', 'SUCCEEDED'));
        $this->assertTrue(PaymentStatus::canTransition('INITIATED', 'SUCCEEDED'));
        $this->assertTrue(PaymentStatus::canTransition('EXPIRED', 'SUCCEEDED'));
        $this->assertFalse(PaymentStatus::canTransition('FAILED', 'SUCCEEDED'));
        $this->assertFalse(PaymentStatus::canTransition('SUCCEEDED', 'PENDING'));
        $this->assertTrue(PaymentStatus::isTerminal('FAILED'));
    }

    public function test_discounts(): void
    {
        $price = Money::of(5000, 'XOF');

        $this->assertSame(1000, DiscountCalculator::discount($price, PromotionType::PERCENTAGE, 20)->amount);
        $this->assertSame(5000, DiscountCalculator::discount($price, PromotionType::FIXED, 9000)->amount); // plafonnée
    }

    public function test_trial_extension_has_no_price_effect(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DiscountCalculator::discount(Money::of(5000, 'XOF'), PromotionType::TRIAL_EXTENSION, 7);
    }

    public function test_hmac_signature_window_and_empty_secret(): void
    {
        $v = new HmacSignatureVerifier(300);
        $sig = $v->sign('s', 1_800_000_000, 'body');

        $this->assertTrue($v->verify('s', '1800000000', $sig, 'body', 1_800_000_300));
        $this->assertFalse($v->verify('s', '1800000000', $sig, 'body', 1_800_000_301));
        $this->assertFalse($v->verify('s', '1800000000', $sig, 'other', 1_800_000_000));
        $this->assertFalse($v->verify('', '1800000000', $v->sign('', 1_800_000_000, 'body'), 'body', 1_800_000_000));
    }

    public function test_mock_provider_rejects_forged_webhook(): void
    {
        $mock = new MockPaymentProvider('secret', true, new HmacSignatureVerifier);

        $this->expectException(InvalidWebhookException::class);
        $mock->parseWebhook('{}', ['x-mock-timestamp' => (string) time(), 'x-mock-signature' => 'sha256=00']);
    }
}
