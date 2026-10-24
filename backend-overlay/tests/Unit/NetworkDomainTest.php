<?php

namespace Tests\Unit;

use App\Domain\Network\RouterOsValue;
use App\Domain\Network\VoucherStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NetworkDomainTest extends TestCase
{
    #[DataProvider('validRates')]
    public function test_valid_rate_limits(string $v): void
    {
        $this->assertTrue(RouterOsValue::isValidRateLimit($v));
    }

    public static function validRates(): array
    {
        return [['512k'], ['2M'], ['2M/2M'], ['1500k/3M'], ['10M/10M']];
    }

    #[DataProvider('invalidRates')]
    public function test_invalid_rate_limits(string $v): void
    {
        $this->assertFalse(RouterOsValue::isValidRateLimit($v));
    }

    public static function invalidRates(): array
    {
        return [[''], ['2M/2M; /system reset'], ["2M\n/ip"], ['2M/2M/2M'], ['$(x)'], ['2T'], ['1234567k'], ['-2M']];
    }

    public function test_codes_are_whitelisted(): void
    {
        $this->assertTrue(RouterOsValue::isValidCode('ABCDEF23'));
        $this->assertFalse(RouterOsValue::isValidCode("ABCDEF23\n"));   // $ de PCRE accepterait ce saut de ligne
        $this->assertFalse(RouterOsValue::isValidCode('ABC DEF2'));
        $this->assertFalse(RouterOsValue::isValidCode('ABCDE'));        // trop court
        $this->assertFalse(RouterOsValue::isValidCode('ABCDEFGHJKMNP')); // trop long
        $this->assertFalse(RouterOsValue::isValidCode('abcdef23'));
        $this->assertFalse(RouterOsValue::isValidCode('OOO000II'));     // caractères ambigus exclus
    }

    public function test_random_codes_use_the_safe_alphabet_and_respect_bounds(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $this->assertTrue(RouterOsValue::isValidCode(RouterOsValue::randomCode(8)));
        }
        $this->expectException(\InvalidArgumentException::class);
        RouterOsValue::randomCode(5);
    }

    public function test_profile_names_come_from_the_uuid(): void
    {
        $name = RouterOsValue::profileName('0192f5a0-7b1c-7d3e-8a4f-123456789abc');
        $this->assertSame('wz-56789abc', $name);
        $this->assertTrue(RouterOsValue::isValidProfileName($name));
        $this->assertFalse(RouterOsValue::isValidProfileName("wz-56789abc\n"));
    }

    public function test_voucher_state_machine(): void
    {
        $this->assertTrue(VoucherStatus::canTransition(VoucherStatus::GENERATED, VoucherStatus::AVAILABLE));
        $this->assertTrue(VoucherStatus::canTransition(VoucherStatus::AVAILABLE, VoucherStatus::SOLD));
        $this->assertTrue(VoucherStatus::canTransition(VoucherStatus::SOLD, VoucherStatus::ACTIVE));
        $this->assertTrue(VoucherStatus::canTransition(VoucherStatus::ACTIVE, VoucherStatus::USED));
        $this->assertTrue(VoucherStatus::canTransition(VoucherStatus::VIP, VoucherStatus::REVOKED));

        foreach ([VoucherStatus::USED, VoucherStatus::EXPIRED, VoucherStatus::REVOKED] as $terminal) {
            $this->assertTrue(VoucherStatus::isTerminal($terminal));
            foreach (VoucherStatus::ALL as $to) {
                $this->assertFalse(VoucherStatus::canTransition($terminal, $to), "$terminal -> $to doit être interdit");
            }
        }

        $this->assertFalse(VoucherStatus::canTransition(VoucherStatus::AVAILABLE, VoucherStatus::GENERATED));
        $this->assertFalse(VoucherStatus::canTransition(VoucherStatus::SOLD, VoucherStatus::AVAILABLE));
    }
}
