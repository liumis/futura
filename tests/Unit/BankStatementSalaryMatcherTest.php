<?php

namespace Tests\Unit;

use App\Enums\BankStatementDirection;
use App\Enums\EmployeeNpdType;
use App\Models\BankStatementImport;
use App\Models\BankStatementTransaction;
use App\Models\Employee;
use App\Models\EmployeeMonthlyPayment;
use App\Services\BankStatements\BankStatementSalaryMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankStatementSalaryMatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_matches_du_full_name_exactly(): void
    {
        $this->makeEmployee('Deividas', 'Arman');
        $this->makeEmployee('Loreta', 'Arman');

        $matched = BankStatementSalaryMatcher::matches(collect([
            $this->makeTransaction(['counterpart_name' => 'DU Deividas Arman']),
        ]));

        $this->assertCount(1, $matched);
        $this->assertSame('Deividas Arman', $matched->first()['employee']->fullName());
    }

    public function test_does_not_match_same_surname_only(): void
    {
        $this->makeEmployee('Deividas', 'Arman');

        $matched = BankStatementSalaryMatcher::matches(collect([
            $this->makeTransaction(['counterpart_name' => 'DU Loreta Arman']),
        ]));

        $this->assertCount(0, $matched);
        $this->assertSame('Loreta Arman', BankStatementSalaryMatcher::salaryPayeeName(
            $this->makeTransaction(['counterpart_name' => 'DU Loreta Arman']),
        ));
    }

    public function test_ignores_bulk_unlabeled_salary_and_allowances(): void
    {
        $this->makeEmployee('Deividas', 'Arman', 'LT121000011101100000');

        $matched = BankStatementSalaryMatcher::matches(collect([
            $this->makeTransaction(['counterpart_name' => 'Atlyginimas už 05 mėn']),
            $this->makeTransaction([
                'counterpart_name' => 'Dienpinigiai Deividas Arman',
                'counterpart_iban' => 'LT121000011101100000',
            ]),
        ]));

        $this->assertCount(0, $matched);
    }

    public function test_suggests_existing_payment_on_the_same_date(): void
    {
        $employee = $this->makeEmployee('Deividas', 'Arman');
        $payment = EmployeeMonthlyPayment::query()->create([
            'employee_id' => $employee->getKey(),
            'payment_date' => '2026-04-01',
            'base_salary' => 2300,
            'gross_amount' => 2300,
            'net_amount' => 2300,
            'status' => 'payed',
            'is_paid' => true,
        ]);
        $transaction = $this->makeTransaction([
            'counterpart_name' => 'DU Deividas Arman',
            'amount' => 2300,
            'booking_date' => '2026-04-01',
            'month_key' => '2026-04',
        ]);

        $suggestion = BankStatementSalaryMatcher::suggestionFor($transaction);

        $this->assertNotNull($suggestion);
        $this->assertSame($payment->getKey(), $suggestion->getKey());
    }

    private function makeEmployee(string $name, string $surname, ?string $iban = null): Employee
    {
        return Employee::query()->create([
            'name' => $name,
            'surname' => $surname,
            'email' => strtolower($name).'.'.strtolower($surname).'@example.com',
            'working_time_percentage' => 100,
            'npd_type' => EmployeeNpdType::Standard,
            'second_pillar_enrolled' => false,
            'bank_account' => $iban,
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
