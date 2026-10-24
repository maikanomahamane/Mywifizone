<?php

namespace Tests\Unit;

use App\Domain\Money\ExchangeRate;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ExchangeRateTest extends TestCase
{
    public function test_parse_and_format_round_trip(): void
    {
        $this->assertSame(65595700000, ExchangeRate::parse('655.957'));
        $this->assertSame('655.957', ExchangeRate::format(65595700000));
        $this->assertSame('1', ExchangeRate::format(ExchangeRate::parse('1.0')));
        $this->assertSame('0.00152449', ExchangeRate::format(ExchangeRate::parse('0.00152449')));
    }

    public function test_parse_rejects_bad_input(): void
    {
        foreach (['0', '0.0', '-1', '1e3', 'abc', '', '1.123456789', '12345678', ' ', '1,5'] as $bad) {
            try {
                ExchangeRate::parse($bad);
                $this->fail("accepté : $bad");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_conversion_between_minor_units_rounds_half_up(): void
    {
        $this->assertSame(6560, ExchangeRate::convert(1000, 2, 0, ExchangeRate::parse('655.957')));
        $this->assertSame(152449, ExchangeRate::convert(1000000, 0, 2, ExchangeRate::parse('0.00152449')));
        $this->assertSame(0, ExchangeRate::convert(1, 0, 0, ExchangeRate::parse('0.4')));
        $this->assertSame(1, ExchangeRate::convert(1, 0, 0, ExchangeRate::parse('0.5')));
        $this->assertSame(0, ExchangeRate::convert(0, 2, 0, ExchangeRate::parse('600')));
        $this->assertSame(100, ExchangeRate::convert(100, 2, 2, ExchangeRate::parse('1')));
    }

    public function test_large_amounts_do_not_overflow_or_lose_precision(): void
    {
        $this->assertSame(999999899990000001, ExchangeRate::convert(99999999999, 0, 0, ExchangeRate::parse('9999999')));
        $this->expectException(InvalidArgumentException::class);
        ExchangeRate::convert(999999999999, 0, 0, ExchangeRate::parse('9999999'));
    }

    public function test_pegs_are_consistent(): void
    {
        foreach (ExchangeRate::PEGS as $pair => $rate) {
            $this->assertGreaterThan(0, ExchangeRate::parse($rate), $pair);
        }
        $this->assertSame(1000000, ExchangeRate::convert(1000000, 0, 0, ExchangeRate::parse(ExchangeRate::PEGS['XOF:XAF'])));
    }
}
