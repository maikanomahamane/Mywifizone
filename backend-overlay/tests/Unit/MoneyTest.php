<?php

namespace Tests\Unit;

use App\Domain\Money\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_arithmetic_uses_integers_and_same_currency(): void
    {
        $price = Money::of(5000, 'xof');

        $this->assertSame('XOF', $price->currency);
        $this->assertSame(6000, $price->add(Money::of(1000, 'XOF'))->amount);
        $this->assertSame(4000, $price->subtract(Money::of(1000, 'XOF'))->amount);
        $this->assertSame(1000, $price->percentOf(20)->amount);
        $this->assertSame(149, Money::of(999, 'XOF')->percentOf(15)->amount); // arrondi inférieur
    }

    public function test_invalid_operations_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of(1000, 'XOF')->add(Money::of(1, 'EUR'));
    }

    public function test_negative_amounts_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of(-1, 'XOF');
    }

    public function test_subtraction_cannot_go_negative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of(1, 'XOF')->subtract(Money::of(2, 'XOF'));
    }
}
