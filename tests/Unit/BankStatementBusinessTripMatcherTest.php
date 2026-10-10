<?php

namespace Tests\Unit;

use App\Enums\BankStatementDirection;
use App\Enums\BusinessTripPaymentStatus;
use App\Enums\BusinessTripRequestStatus;
use App\Enums\EmployeeNpdType;
use App\Models\BankStatementImport;
use App\Models\BankStatementTransaction;
use App\Models\BusinessTripDailyAllowance;
use App\Models\BusinessTripRequest;
use App\Models\Employee;
use App\Services\BankStatements\BankStatementBusinessTripMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankStatementBusinessTripMatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_detects_dienpinigiai_transfer(): void
    {
        $transaction = $this->makeTransaction([
            'counterpart_name' => 'Dienpinigiai Deividas Arman',
            'description' => 'Komandiruotė LT',
        ]);

        $this->assertTrue(BankStatementBusinessTripMatcher::isBusinessTripTransfer($transaction));
    }

    public function test_suggests_trip_by_amount_date_and_employee_name(): void
    {
        $employee = $this->makeEmployee('Deividas', 'Arman');
        $trip = $this->makeTrip($employee, [
            'country_name' => 'Lenkija',
            'date_from' => '2026-08-10',
            'date_to' => '2026-08-14',
            'payable_amount' => 280.50,
            'amount' => 280.50,
        ]);

        $transaction = $this->makeTransaction([
            'counterpart_name' => 'Dienpinigiai Deividas Arman',
            'description' => 'Komandiruotė Lenkija',
            'amount' => 280.50,
            'booking_date' => '2026-08-18',
            'month_key' => '2026-08',
        ]);

        $suggestion = BankStatementBusinessTripMatcher::suggestionFor($transaction);

        $this->assertNotNull($suggestion);
        $this->assertSame($trip->getKey(), $suggestion->getKey());
    }

    public function test_link_marks_trip_paid_and_unlink_reverts(): void
    {
        $employee = $this->makeEmployee('Deividas', 'Arman');
        $trip = $this->makeTrip($employee, [
            'payable_amount' => 100,
            'amount' => 100,
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-03',
        ]);
        $transaction = $this->makeTransaction([
            'amount' => 100,
            'booking_date' => '2026-08-05',
            'month_key' => '2026-08',
            'counterpart_name' => 'Dienpinigiai Deividas Arman',
        ]);

        BankStatementBusinessTripMatcher::link($transaction, $trip);

        $transaction->refresh();
        $trip->refresh();

        $this->assertSame($trip->getKey(), (int) $transaction->business_trip_request_id);
        $this->assertSame(BusinessTripPaymentStatus::Paid, $trip->payment_status);
        $this->assertNotNull($trip->paid_at);

        BankStatementBusinessTripMatcher::unlink($transaction->fresh());

        $transaction->refresh();
        $trip->refresh();

        $this->assertNull($transaction->business_trip_request_id);
        $this->assertSame(BusinessTripPaymentStatus::Unpaid, $trip->payment_status);
        $this->assertNull($trip->paid_at);
    }

    public function test_allows_linking_several_payments_to_one_trip(): void
    {
        $employee = $this->makeEmployee('Deividas', 'Arman');
        $trip = $this->makeTrip($employee, [
            'payable_amount' => 280.50,
            'amount' => 280.50,
            'date_from' => '2026-08-10',
            'date_to' => '2026-08-14',
        ]);

        $first = $this->makeTransaction([
            'amount' => 100.00,
            'booking_date' => '2026-08-16',
            'month_key' => '2026-08',
            'counterpart_name' => 'Dienpinigiai Deividas Arman',
            'fingerprint' => bin2hex(random_bytes(16)),
        ]);
        $second = $this->makeTransaction([
            'amount' => 180.50,
            'booking_date' => '2026-08-18',
            'month_key' => '2026-08',
            'counterpart_name' => 'Dienpinigiai Deividas Arman',
            'fingerprint' => bin2hex(random_bytes(16)),
        ]);

        BankStatementBusinessTripMatcher::link($first, $trip);
        BankStatementBusinessTripMatcher::link($second, $trip->fresh(['bankStatementTransactions']));

        $first->refresh();
        $second->refresh();
        $trip->refresh();

        $this->assertSame($trip->getKey(), (int) $first->business_trip_request_id);
        $this->assertSame($trip->getKey(), (int) $second->business_trip_request_id);
        $this->assertSame(2, $trip->bankStatementTransactions()->count());
        $this->assertSame(BusinessTripPaymentStatus::Paid, $trip->payment_status);

        BankStatementBusinessTripMatcher::unlink($first->fresh());
        $trip->refresh();

        $this->assertSame(1, $trip->bankStatementTransactions()->count());
        $this->assertSame(BusinessTripPaymentStatus::Partly, $trip->payment_status);

        BankStatementBusinessTripMatcher::unlink($second->fresh());
        $trip->refresh();

        $this->assertSame(0, $trip->bankStatementTransactions()->count());
        $this->assertSame(BusinessTripPaymentStatus::Unpaid, $trip->payment_status);
    }

    public function test_link_marks_partly_when_expenses_remain(): void
    {
        $employee = $this->makeEmployee('Deividas', 'Arman');
        $trip = $this->makeTrip($employee, [
            'payable_amount' => 100,
            'amount' => 100,
            'expenses_amount' => 50,
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-03',
        ]);
        $transaction = $this->makeTransaction([
            'amount' => 100,
            'booking_date' => '2026-08-05',
            'month_key' => '2026-08',
            'counterpart_name' => 'Dienpinigiai Deividas Arman',
        ]);

        BankStatementBusinessTripMatcher::link($transaction, $trip);

        $trip->refresh();
        $this->assertSame(BusinessTripPaymentStatus::Partly, $trip->payment_status);
        $this->assertSame(150.0, $trip->expectedTotal());

        $expensePayment = $this->makeTransaction([
            'amount' => 50,
            'booking_date' => '2026-08-06',
            'month_key' => '2026-08',
            'counterpart_name' => 'Hotel receipt Deividas Arman',
            'fingerprint' => bin2hex(random_bytes(16)),
        ]);
        BankStatementBusinessTripMatcher::link($expensePayment, $trip->fresh(['bankStatementTransactions']));

        $trip->refresh();
        $this->assertSame(BusinessTripPaymentStatus::Paid, $trip->payment_status);

        $summary = $transaction->fresh()->load('businessTripRequest.bankStatementTransactions')->settlementSummary();
        $this->assertSame('paid', $summary['status']);
        $this->assertSame('Paid', $summary['label']);
    }

    public function test_suggests_remaining_amount_including_expenses(): void
    {
        $employee = $this->makeEmployee('Deividas', 'Arman');
        $trip = $this->makeTrip($employee, [
            'country_name' => 'Lenkija',
            'date_from' => '2026-08-10',
            'date_to' => '2026-08-14',
            'payable_amount' => 280.50,
            'amount' => 280.50,
            'expenses_amount' => 40,
        ]);

        $first = $this->makeTransaction([
            'counterpart_name' => 'Dienpinigiai Deividas Arman',
            'description' => 'Komandiruotė Lenkija',
            'amount' => 280.50,
            'booking_date' => '2026-08-16',
            'month_key' => '2026-08',
            'fingerprint' => bin2hex(random_bytes(16)),
        ]);
        BankStatementBusinessTripMatcher::link($first, $trip);

        $second = $this->makeTransaction([
            'counterpart_name' => 'Dienpinigiai Deividas Arman',
            'description' => 'Komandiruotė Lenkija',
            'amount' => 40.00,
            'booking_date' => '2026-08-18',
            'month_key' => '2026-08',
            'fingerprint' => bin2hex(random_bytes(16)),
        ]);

        $suggestion = BankStatementBusinessTripMatcher::suggestionFor($second);

        $this->assertNotNull($suggestion);
        $this->assertSame($trip->getKey(), $suggestion->getKey());
    }

    public function test_suggests_remaining_amount_after_partial_link(): void
    {
        $employee = $this->makeEmployee('Deividas', 'Arman');
        $trip = $this->makeTrip($employee, [
            'country_name' => 'Lenkija',
            'date_from' => '2026-08-10',
            'date_to' => '2026-08-14',
            'payable_amount' => 280.50,
            'amount' => 280.50,
        ]);

        $first = $this->makeTransaction([
            'counterpart_name' => 'Dienpinigiai Deividas Arman',
            'description' => 'Komandiruotė Lenkija',
            'amount' => 100.00,
            'booking_date' => '2026-08-16',
            'month_key' => '2026-08',
            'fingerprint' => bin2hex(random_bytes(16)),
        ]);
        BankStatementBusinessTripMatcher::link($first, $trip);

        $second = $this->makeTransaction([
            'counterpart_name' => 'Dienpinigiai Deividas Arman',
            'description' => 'Komandiruotė Lenkija',
            'amount' => 180.50,
            'booking_date' => '2026-08-18',
            'month_key' => '2026-08',
            'fingerprint' => bin2hex(random_bytes(16)),
        ]);

        $suggestion = BankStatementBusinessTripMatcher::suggestionFor($second);

        $this->assertNotNull($suggestion);
        $this->assertSame($trip->getKey(), $suggestion->getKey());
    }

    public function test_does_not_suggest_when_amount_differs(): void
    {
        $employee = $this->makeEmployee('Deividas', 'Arman');
        $this->makeTrip($employee, [
            'payable_amount' => 280.50,
            'amount' => 280.50,
            'date_from' => '2026-08-10',
            'date_to' => '2026-08-14',
        ]);

        $transaction = $this->makeTransaction([
            'counterpart_name' => 'Dienpinigiai Deividas Arman',
            'amount' => 200,
            'booking_date' => '2026-08-18',
            'month_key' => '2026-08',
        ]);

        $this->assertNull(BankStatementBusinessTripMatcher::suggestionFor($transaction));
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
    private function makeTrip(Employee $employee, array $overrides = []): BusinessTripRequest
    {
        $allowance = BusinessTripDailyAllowance::query()->create([
            'list_number' => 1,
            'sort_order' => 1,
            'country' => 'Lenkija',
            'daily_rate' => 50,
        ]);

        return BusinessTripRequest::query()->create(array_merge([
            'employee_id' => $employee->getKey(),
            'business_trip_daily_allowance_id' => $allowance->getKey(),
            'country_name' => 'Lenkija',
            'daily_rate' => 50,
            'date_from' => '2026-08-10',
            'date_to' => '2026-08-14',
            'calendar_days' => 5,
            'payable_units' => 5,
            'amount' => 250,
            'non_taxable_amount' => 250,
            'taxable_amount' => 0,
            'gpm_amount' => 0,
            'payable_amount' => 250,
            'status' => BusinessTripRequestStatus::Confirmed,
            'payment_status' => BusinessTripPaymentStatus::Unpaid,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeTransaction(array $overrides = []): BankStatementTransaction
    {
        $import = BankStatementImport::query()->create([
            'original_filename' => 'seb.xml',
            'transaction_count' => 1,
            'imported_count' => 1,
            'skipped_count' => 0,
        ]);

        return BankStatementTransaction::query()->create(array_merge([
            'bank_statement_import_id' => $import->getKey(),
            'fingerprint' => bin2hex(random_bytes(16)),
            'month_key' => '2026-08',
            'booking_date' => '2026-08-18',
            'direction' => BankStatementDirection::Debit,
            'amount' => 280.50,
            'currency' => 'EUR',
            'counterpart_name' => 'Dienpinigiai Deividas Arman',
            'description' => 'Komandiruotė',
        ], $overrides));
    }
}
