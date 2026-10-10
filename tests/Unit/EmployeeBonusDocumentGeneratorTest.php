<?php

namespace Tests\Unit;

use App\Enums\EmployeeNpdType;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\EmployeeMonthlyPayment;
use App\Models\PayrollTaxSetting;
use App\Services\EmployeeBonusOrderPdfGenerator;
use App\Services\ShareholderBonusDecisionPdfGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EmployeeBonusDocumentGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_bonus_order_pdf_builds_for_payment_with_bonus(): void
    {
        PayrollTaxSetting::forYear(2026);
        CompanySetting::query()->create(['company_name' => 'UAB Test', 'company_id' => '123456789']);

        $employee = Employee::query()->create([
            'name' => 'Jonas',
            'surname' => 'Jonaitis',
            'working_time_percentage' => 100,
            'npd_type' => EmployeeNpdType::Standard,
        ]);

        $payment = EmployeeMonthlyPayment::query()->create([
            'employee_id' => $employee->getKey(),
            'payment_date' => Carbon::parse('2026-07-15'),
            'base_salary' => 4959,
            'bonus_payment' => 500,
            'status' => 'open',
        ]);

        $payload = EmployeeBonusOrderPdfGenerator::build($payment->fresh('employee'));
        $this->assertSame('Jonas Jonaitis', $payload['employeeName']);
        $this->assertSame(500.0, $payload['bonusAmountRaw']);
        $this->assertStringContainsString('2026', $payload['periodLabel']);

        $pdf = EmployeeBonusOrderPdfGenerator::generate($payment->fresh('employee'));
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_shareholder_decision_pdf_for_general_manager_bonus(): void
    {
        PayrollTaxSetting::forYear(2026);
        CompanySetting::query()->create(['company_name' => 'UAB Test']);

        $employee = Employee::query()->create([
            'name' => 'General',
            'surname' => 'Manager',
            'is_general_manager' => true,
            'working_time_percentage' => 100,
        ]);

        $payment = EmployeeMonthlyPayment::query()->create([
            'employee_id' => $employee->getKey(),
            'payment_date' => Carbon::parse('2026-07-15'),
            'base_salary' => 4959,
            'bonus_payment' => 1000,
            'status' => 'open',
        ]);

        $payload = ShareholderBonusDecisionPdfGenerator::build($payment->fresh('employee'));
        $this->assertSame('General Manager', $payload['employeeName']);
        $this->assertSame(1000.0, $payload['bonusAmountRaw']);

        $pdf = ShareholderBonusDecisionPdfGenerator::generate($payment->fresh('employee'));
        $this->assertStringStartsWith('%PDF', $pdf);
    }
}
