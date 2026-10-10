<?php

namespace Tests\Unit;

use App\Enums\BankStatementDirection;
use App\Enums\EmployeeNpdType;
use App\Models\BankStatementImport;
use App\Models\BankStatementTransaction;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\EmployeeMonthlyPayment;
use App\Models\PayrollTaxSetting;
use App\Services\HistoricalEmployeePayslipGenerator;
use App\Services\LithuanianPayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HistoricalEmployeePayslipGeneratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        PayrollTaxSetting::forYear(2026);
        CompanySetting::query()->create([
            'company_name' => 'Futura Textiles Europe, UAB',
            'company_id' => '3067400042',
        ]);
    }

    public function test_creates_payed_payment_payslip_and_links_bank_line_from_net(): void
    {
        $employee = $this->makeEmployee('Deividas', 'Arman');
        $transaction = $this->makeTransaction([
            'counterpart_name' => 'DU Deividas Arman',
            'amount' => 1276.39,
            'booking_date' => '2026-08-22',
            'month_key' => '2026-08',
        ]);
        $this->makeTransaction([
            'counterpart_name' => 'DU Loreta Arman',
            'amount' => 900,
            'booking_date' => '2026-08-22',
            'month_key' => '2026-08',
        ]);
        $this->makeTransaction([
            'counterpart_name' => 'Atlyginimas už 08 mėn',
            'amount' => 4000,
            'booking_date' => '2026-08-22',
            'month_key' => '2026-08',
        ]);

        $result = HistoricalEmployeePayslipGenerator::run(email: false);

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['payslips']);
        $this->assertSame(1, $result['linked']);
        $this->assertNotEmpty($result['unmatched']);
        $this->assertTrue(collect($result['unmatched'])->contains(
            fn (string $label): bool => str_contains($label, 'Loreta Arman'),
        ));

        $payment = EmployeeMonthlyPayment::query()->first();
        $this->assertNotNull($payment);
        $this->assertSame($employee->getKey(), (int) $payment->employee_id);
        $this->assertSame('2026-08-22', $payment->payment_date?->toDateString());
        $this->assertSame('payed', $payment->status->value);
        $this->assertEqualsWithDelta(1276.39, (float) $payment->net_amount, 0.001);
        $this->assertEqualsWithDelta(2000.00, (float) $payment->gross_amount, 0.05);
        $this->assertNotNull($payment->payslip_document_id);

        $transaction->refresh();
        $this->assertSame($payment->getKey(), (int) $transaction->employee_monthly_payment_id);
        $this->assertTrue(Storage::disk('public')->exists($payment->payslipDocument?->file_path));
    }

    public function test_skips_when_existing_net_differs(): void
    {
        $employee = $this->makeEmployee('Deividas', 'Arman');
        EmployeeMonthlyPayment::query()->create([
            'employee_id' => $employee->getKey(),
            'payment_date' => '2026-04-01',
            'base_salary' => 1000,
            'gross_amount' => 1000,
            'net_amount' => 800,
            'status' => 'payed',
            'is_paid' => true,
        ]);
        $this->makeTransaction([
            'counterpart_name' => 'DU Deividas Arman',
            'amount' => 2300,
            'booking_date' => '2026-04-01',
            'month_key' => '2026-04',
        ]);

        $result = HistoricalEmployeePayslipGenerator::run(email: false);

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, EmployeeMonthlyPayment::query()->count());
        $this->assertNull(BankStatementTransaction::query()->value('employee_monthly_payment_id'));
        $this->assertEqualsWithDelta(800, (float) EmployeeMonthlyPayment::query()->value('net_amount'), 0.001);
    }

    public function test_dry_run_does_not_write(): void
    {
        $this->makeEmployee('Deividas', 'Arman');
        $this->makeTransaction(['counterpart_name' => 'DU Deividas Arman', 'amount' => 1276.39]);

        $result = HistoricalEmployeePayslipGenerator::run(dryRun: true, email: false);

        $this->assertSame(1, $result['created']);
        $this->assertSame(0, EmployeeMonthlyPayment::query()->count());
        $this->assertNull(BankStatementTransaction::query()->value('employee_monthly_payment_id'));
        $this->assertSame(1, $result['linked']);
    }

    public function test_calculate_from_net_round_trips_example_payslip(): void
    {
        $tax = app(LithuanianPayrollCalculator::class)->calculateFromNet(1276.39, EmployeeNpdType::Standard, year: 2026);

        $this->assertSame(1276.39, $tax['net']);
        $this->assertEqualsWithDelta(2000.00, $tax['gross'], 0.05);
    }

    private function makeEmployee(string $name, string $surname): Employee
    {
        return Employee::query()->create([
            'name' => $name,
            'surname' => $surname,
            'email' => strtolower($name).'.'.strtolower($surname).'@example.com',
            'working_time_percentage' => 100,
            'npd_type' => EmployeeNpdType::Standard,
            'second_pillar_enrolled' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeTransaction(array $overrides = []): BankStatementTransaction
    {
        $import = BankStatementImport::query()->create([
            'original_filename' => 'swedbank.xml',
            'transaction_count' => 1,
            'imported_count' => 1,
            'skipped_count' => 0,
        ]);

        return BankStatementTransaction::query()->create(array_merge([
            'bank_statement_import_id' => $import->getKey(),
            'fingerprint' => bin2hex(random_bytes(16)),
            'month_key' => '2026-04',
            'booking_date' => '2026-04-01',
            'direction' => BankStatementDirection::Debit,
            'amount' => 2300,
            'currency' => 'EUR',
            'counterpart_name' => 'DU Deividas Arman',
            'description' => 'DU',
        ], $overrides));
    }
}
