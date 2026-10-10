<?php

namespace Tests\Unit;

use App\Services\BusinessTripAllowanceCalculator;
use InvalidArgumentException;
use Tests\TestCase;

class BusinessTripAllowanceCalculatorTest extends TestCase
{
    public function test_full_multi_day_trip_pays_full_daily_rate_each_day(): void
    {
        $result = BusinessTripAllowanceCalculator::calculate(
            50.0,
            '2026-09-01',
            '2026-09-03',
            8,
            8,
        );

        $this->assertSame(3, $result['calendar_days']);
        $this->assertSame(3.0, $result['payable_units']);
        $this->assertSame(150.0, $result['amount']);
    }

    public function test_first_and_last_day_under_four_hours_pay_twenty_percent(): void
    {
        $result = BusinessTripAllowanceCalculator::calculate(
            50.0,
            '2026-09-01',
            '2026-09-05',
            3.5,
            2,
        );

        // 0.2 + 3 middle + 0.2 = 3.4 units × 50 = 170
        $this->assertSame(5, $result['calendar_days']);
        $this->assertSame(3.4, $result['payable_units']);
        $this->assertSame(170.0, $result['amount']);
        $this->assertSame(0.2, $result['first_day_factor']);
        $this->assertSame(0.2, $result['last_day_factor']);
        $this->assertSame(3, $result['middle_days']);
    }

    public function test_single_day_under_four_hours_pays_twenty_percent_once(): void
    {
        $result = BusinessTripAllowanceCalculator::calculate(
            69.0,
            '2026-09-10',
            '2026-09-10',
            3,
            8,
        );

        $this->assertSame(1, $result['calendar_days']);
        $this->assertSame(0.2, $result['payable_units']);
        $this->assertSame(13.8, $result['amount']);
    }

    public function test_exactly_four_hours_counts_as_full_day(): void
    {
        $result = BusinessTripAllowanceCalculator::calculate(
            40.0,
            '2026-09-01',
            '2026-09-02',
            4,
            4,
        );

        $this->assertSame(2.0, $result['payable_units']);
        $this->assertSame(80.0, $result['amount']);
    }

    public function test_rejects_inverted_dates(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BusinessTripAllowanceCalculator::calculate(10, '2026-09-10', '2026-09-01');
    }
}
