<?php

namespace Tests\Unit;

use App\Enums\EmployeeNpdType;
use App\Models\PayrollTaxSetting;
use App\Services\LithuanianPayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LithuanianPayrollCalculatorTest extends TestCase
{
    use RefreshDatabase;

    protected LithuanianPayrollCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new LithuanianPayrollCalculator;
        PayrollTaxSetting::forYear(2026);
    }

    public function test_standard_npd_at_or_below_mma(): void
    {
        $result = $this->calculator->calculate(1153.00, EmployeeNpdType::Standard, year: 2026);

        $this->assertSame(1153.00, $result['gross']);
        $this->assertSame(747.00, $result['npd']);
        $this->assertSame(224.84, $result['sodra_employee']); // 19.5%
        $this->assertSame(81.20, $result['gpm']); // 20% of (1153 - 747)
        $this->assertSame(846.96, $result['net']);
    }

    public function test_standard_npd_above_mma(): void
    {
        // NPD = 747 - 0.49 * (2000 - 1153) = 747 - 415.03 = 331.97
        $result = $this->calculator->calculate(2000.00, EmployeeNpdType::Standard, year: 2026);

        $this->assertSame(2000.00, $result['gross']);
        $this->assertSame(331.97, $result['npd']);
        $this->assertSame(390.00, $result['sodra_employee']);
        $this->assertSame(333.61, $result['gpm']); // 20% of (2000 - 331.97)
        $this->assertSame(1276.39, $result['net']);
    }

    public function test_disability_0_25_vmi_group(): void
    {
        $result = $this->calculator->calculate(1500.00, EmployeeNpdType::Disability0To25, year: 2026);

        $this->assertSame(1127.00, $result['npd']);
        $this->assertSame(292.50, $result['sodra_employee']);
        $this->assertSame(74.60, $result['gpm']); // 20% of (1500 - 1127)
        $this->assertSame(1132.90, $result['net']);
    }

    public function test_disability_30_55_vmi_group(): void
    {
        $result = $this->calculator->calculate(1500.00, EmployeeNpdType::Disability30To55, year: 2026);

        $this->assertSame(1057.00, $result['npd']);
        $this->assertSame(88.60, $result['gpm']); // 20% of (1500 - 1057)
    }

    public function test_no_npd(): void
    {
        $result = $this->calculator->calculate(1153.00, EmployeeNpdType::None, year: 2026);

        $this->assertSame(0.0, $result['npd']);
        $this->assertSame(230.60, $result['gpm']);
    }

    public function test_second_pillar_adds_to_sodra(): void
    {
        $without = $this->calculator->calculate(1153.00, EmployeeNpdType::Standard, false, null, 2026);
        $with = $this->calculator->calculate(1153.00, EmployeeNpdType::Standard, true, 0.03, 2026);

        $this->assertSame(0.195, $without['sodra_rate']);
        $this->assertSame(0.225, $with['sodra_rate']);
        $this->assertSame(259.43, $with['sodra_employee']); // 22.5% of 1153
        $this->assertTrue($with['net'] < $without['net']);
    }

    public function test_employer_sodra_permanent_rate(): void
    {
        $result = $this->calculator->calculate(1153.00, EmployeeNpdType::Standard, year: 2026);

        $this->assertSame(0.0177, $result['employer_sodra_rate']);
        $this->assertSame(20.41, $result['sodra_employer']); // 1.77% of 1153
    }

    public function test_small_gross_shows_full_npd(): void
    {
        $result = $this->calculator->calculate(100.00, EmployeeNpdType::Standard, year: 2026);

        $this->assertSame(100.00, $result['gross']);
        $this->assertSame(747.00, $result['npd']);
        $this->assertSame(6.98, $result['sodra_health']);
        $this->assertSame(12.52, $result['sodra_pension']);
        $this->assertSame(19.50, $result['sodra_employee']);
        $this->assertSame(0.0, $result['gpm']);
        $this->assertSame(80.50, $result['net']);
        $this->assertSame(101.77, $result['workplace_cost']);
    }

    public function test_small_gross_with_second_pillar_matches_reference_calculator(): void
    {
        $result = $this->calculator->calculate(100.00, EmployeeNpdType::Standard, true, 0.03, 2026);

        $this->assertSame(747.00, $result['npd']);
        $this->assertSame(6.98, $result['sodra_health']);
        $this->assertSame(15.52, $result['sodra_pension']);
        $this->assertSame(22.50, $result['sodra_employee']);
        $this->assertSame(77.50, $result['net']);
        $this->assertSame(101.77, $result['workplace_cost']);
    }

    public function test_employer_sodra_fixed_term_rate(): void
    {
        $result = $this->calculator->calculate(
            1153.00,
            EmployeeNpdType::Standard,
            year: 2026,
            fixedTermContract: true,
        );

        $this->assertSame(0.0249, $result['employer_sodra_rate']);
        $this->assertSame(28.71, $result['sodra_employer']); // 2.49% of 1153
    }
}
