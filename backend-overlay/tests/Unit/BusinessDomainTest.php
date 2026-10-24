<?php

namespace Tests\Unit;

use App\Domain\Business\CashType;
use App\Domain\Business\ReportPeriod;
use PHPUnit\Framework\TestCase;

class BusinessDomainTest extends TestCase
{
    public function test_cash_types_decide_the_direction_of_the_entry(): void
    {
        $this->assertSame(100, CashType::signed(CashType::SALE, 100));
        $this->assertSame(-100, CashType::signed(CashType::EXPENSE, 100));
        $this->assertSame(-100, CashType::signed(CashType::WITHDRAWAL, -100));
        $this->assertSame(-30, CashType::signed(CashType::ADJUSTMENT, -30));
    }

    public function test_report_buckets(): void
    {
        $this->assertSame('2026-10-11', ReportPeriod::bucket('daily', '2026-10-11'));
        $this->assertSame('2026-10-05', ReportPeriod::bucket('weekly', '2026-10-11'));   // dimanche -> lundi précédent
        $this->assertSame('2026-10-12', ReportPeriod::bucket('weekly', '2026-10-12'));
        $this->assertSame('2026-10-01', ReportPeriod::bucket('monthly', '2026-10-31'));
        $this->assertSame('2026-01-01', ReportPeriod::bucket('annual', '2026-12-31'));
        $this->assertSame('2025-12-29', ReportPeriod::bucket('weekly', '2026-01-02'));   // semaine à cheval sur deux années
    }
}
