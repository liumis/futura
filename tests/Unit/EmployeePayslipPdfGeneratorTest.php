<?php

namespace Tests\Unit;

use App\Enums\EmployeeNpdType;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\EmployeeMonthlyPayment;
use App\Models\PayrollTaxSetting;
use App\Services\EmployeePayslipPdfGenerator;
use App\Services\LithuanianPayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EmployeePayslipPdfGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_payslip_build_matches_payment_snapshot_totals(): void
    {
        PayrollTaxSetting::forYear(2026);
        CompanySetting::query()->create([
            'company_name' => 'UAB Test',
            'company_id' => '123456789',
        ]);

        $employee = Employee::query()->create([
            'name' => 'Deividas',
            'surname' => 'Arman',
            'email' => 'deividas@example.com',
            'working_time_percentage' => 100,
            'npd_type' => EmployeeNpdType::Standard,
            'second_pillar_enrolled' => false,
        ]);

        $tax = app(LithuanianPayrollCalculator::class)->calculate(2000.0, $employee, year: 2026);

        $payment = EmployeeMonthlyPayment::query()->create([
            'employee_id' => $employee->getKey(),
            'payment_date' => Carbon::parse('2026-08-20'),
            'base_salary' => 2000,
            'bonus_payment' => null,
            'gross_amount' => $tax['gross'],
            'npd_amount' => $tax['npd'],
            'sodra_employee_amount' => $tax['sodra_employee'],
            'sodra_employer_amount' => $tax['sodra_employer'],
            'gpm_amount' => $tax['gpm'],
            'net_amount' => $tax['net'],
            'status' => 'open',
        ]);

        $payload = EmployeePayslipPdfGenerator::build($payment->fresh('employee'));

        $this->assertSame('Deividas Arman', $payload['employeeName']);
        $this->assertStringContainsString('2026', $payload['periodLabel']);
        $this->assertSame(2000.0, $payload['baseSalary']);
        $this->assertSame($tax['npd'], $payload['npd']);
        $this->assertSame($tax['net'], $payload['netAmount']);
        $this->assertSame($tax['gpm'], $payload['deductions'][0]['amount']);
        $this->assertSame($tax['sodra_employer'], $payload['sodraEmployer']);

        $pdf = EmployeePayslipPdfGenerator::generate($payment->fresh('employee'));
        $this->assertNotSame('', $pdf);
        $this->assertStringStartsWith('%PDF', $pdf);
    }
}
