<?php

namespace Tests\Unit;

use App\Enums\BusinessTripPaymentStatus;
use App\Enums\BusinessTripRequestStatus;
use App\Enums\EmployeeContractStatus;
use App\Models\BusinessTripDailyAllowance;
use App\Models\BusinessTripRequest;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\PayrollTaxSetting;
use App\Services\BusinessTripAllowanceTaxCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessTripAllowanceTaxCalculatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        PayrollTaxSetting::query()->updateOrCreate(
            ['year' => 2025],
            [
                'mma' => 1038.00,
                'mva' => 6.3500,
                'npd_max' => 747.00,
                'npd_coefficient' => 0.49,
                'npd_disability_0_25' => 1127.00,
                'npd_disability_30_55' => 1057.00,
                'employee_sodra_rate' => 0.1950,
                'gpm_rate' => 0.2000,
                'employer_sodra_permanent_rate' => 0.0177,
                'employer_sodra_fixed_term_rate' => 0.0249,
            ],
        );
    }

    public function test_high_salary_makes_entire_allowance_non_taxable(): void
    {
        $settings = PayrollTaxSetting::forYear(2025);

        $result = BusinessTripAllowanceTaxCalculator::calculate(
            868.0,
            1800.0,
            $settings,
            '2025-09-01',
        );

        $this->assertFalse($result['is_low_salary_case']);
        $this->assertSame(1712.70, $result['salary_threshold_snapshot']);
        $this->assertSame(868.0, $result['non_taxable_amount']);
        $this->assertSame(0.0, $result['taxable_amount']);
        $this->assertSame(0.0, $result['gpm_amount']);
        $this->assertSame(868.0, $result['payable_amount']);
    }

    public function test_low_salary_under_half_du_is_fully_non_taxable(): void
    {
        // Slide: DU 1500, 10×62 = 620 ≤ 750
        $settings = PayrollTaxSetting::forYear(2025);

        $result = BusinessTripAllowanceTaxCalculator::calculate(
            620.0,
            1500.0,
            $settings,
            '2025-09-01',
        );

        $this->assertTrue($result['is_low_salary_case']);
        $this->assertSame(750.0, $result['month_free_cap_snapshot']);
        $this->assertSame(620.0, $result['non_taxable_amount']);
        $this->assertSame(0.0, $result['taxable_amount']);
        $this->assertSame(0.0, $result['gpm_amount']);
        $this->assertSame(620.0, $result['payable_amount']);
    }

    public function test_low_salary_excess_over_half_du_is_taxed_at_twenty_percent(): void
    {
        // Slide: DU 1500, 14×62 = 868 → 750 free, 118 taxable, GPM 23.60
        $settings = PayrollTaxSetting::forYear(2025);

        $result = BusinessTripAllowanceTaxCalculator::calculate(
            868.0,
            1500.0,
            $settings,
            '2025-09-01',
        );

        $this->assertTrue($result['is_low_salary_case']);
        $this->assertSame(750.0, $result['month_free_cap_snapshot']);
        $this->assertSame(750.0, $result['non_taxable_amount']);
        $this->assertSame(118.0, $result['taxable_amount']);
        $this->assertSame(23.60, $result['gpm_amount']);
        $this->assertSame(844.40, $result['payable_amount']);
    }

    public function test_second_trip_in_month_only_taxes_remaining_excess(): void
    {
        // First trip already consumed 620 of the 750 cap → second 248 gets 130 free + 118 taxable
        $settings = PayrollTaxSetting::forYear(2025);

        $result = BusinessTripAllowanceTaxCalculator::calculate(
            248.0,
            1500.0,
            $settings,
            '2025-09-01',
            otherMonthGross: 620.0,
        );

        $this->assertSame(130.0, $result['non_taxable_amount']);
        $this->assertSame(118.0, $result['taxable_amount']);
        $this->assertSame(23.60, $result['gpm_amount']);
        $this->assertSame(224.40, $result['payable_amount']);
    }

    public function test_hourly_rate_above_mva_threshold_skips_low_salary_rule(): void
    {
        $settings = PayrollTaxSetting::forYear(2025);

        $result = BusinessTripAllowanceTaxCalculator::calculate(
            868.0,
            1000.0,
            $settings,
            '2025-09-01',
            hourlyRate: 11.0,
        );

        $this->assertFalse($result['is_low_salary_case']);
        $this->assertSame(0.0, $result['gpm_amount']);
        $this->assertSame(868.0, $result['payable_amount']);
    }

    public function test_monthly_aggregation_uses_other_confirmed_trips(): void
    {
        $employee = Employee::query()->create([
            'name' => 'Test',
            'surname' => 'Worker',
            'email' => 'trip-tax@example.com',
        ]);

        EmployeeContract::query()->create([
            'employee_id' => $employee->getKey(),
            'sign_date' => '2025-01-01',
            'effective_date_from' => '2025-01-01',
            'base_salary' => 1500,
            'status' => EmployeeContractStatus::Signed,
            'state_percentage' => 100,
        ]);

        $allowance = BusinessTripDailyAllowance::query()->create([
            'list_number' => 1,
            'sort_order' => 1,
            'country' => 'Vokietija',
            'daily_rate' => 62,
        ]);

        BusinessTripRequest::query()->create([
            'employee_id' => $employee->getKey(),
            'business_trip_daily_allowance_id' => $allowance->getKey(),
            'country_name' => 'Vokietija',
            'daily_rate' => 62,
            'date_from' => '2025-09-01',
            'date_to' => '2025-09-10',
            'calendar_days' => 10,
            'payable_units' => 10,
            'amount' => 620,
            'status' => BusinessTripRequestStatus::Confirmed,
            'confirmed_at' => '2025-09-15 10:00:00',
            'payment_status' => BusinessTripPaymentStatus::Unpaid,
            'non_taxable_amount' => 620,
            'taxable_amount' => 0,
            'gpm_amount' => 0,
            'payable_amount' => 620,
        ]);

        $result = BusinessTripAllowanceTaxCalculator::forRequest(
            248.0,
            (int) $employee->getKey(),
            '2025-09-01',
        );

        $this->assertSame(620.0, $result['other_month_gross']);
        $this->assertSame(130.0, $result['non_taxable_amount']);
        $this->assertSame(118.0, $result['taxable_amount']);
        $this->assertSame(23.60, $result['gpm_amount']);
    }
}
