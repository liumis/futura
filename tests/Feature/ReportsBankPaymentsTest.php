<?php

namespace Tests\Feature;

use App\Enums\BankStatementDirection;
use App\Filament\Admin\Pages\ReportsBankPayments;
use App\Models\BankStatementImport;
use App\Models\BankStatementTransaction;
use App\Models\User;
use App\Services\BankPaymentsChartReportBuilder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportsBankPaymentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_bank_payment_report(): void
    {
        $this->actingAsAdmin();

        Livewire::test(ReportsBankPayments::class)
            ->assertOk()
            ->assertSee('Bank payment report')
            ->assertSee('Sum')
            ->assertSee('Count');
    }

    public function test_builder_groups_by_booking_day_for_sum_and_count(): void
    {
        $this->actingAsAdmin();

        $this->makeTransaction([
            'booking_date' => '2026-09-01',
            'direction' => BankStatementDirection::Credit,
            'amount' => 100,
        ]);
        $this->makeTransaction([
            'booking_date' => '2026-09-01',
            'direction' => BankStatementDirection::Debit,
            'amount' => 40,
        ]);
        $this->makeTransaction([
            'booking_date' => '2026-09-02',
            'direction' => BankStatementDirection::Credit,
            'amount' => 25,
        ]);

        $sum = BankPaymentsChartReportBuilder::build(
            '2026-09-01',
            '2026-09-30',
            BankPaymentsChartReportBuilder::METRIC_SUM,
            BankPaymentsChartReportBuilder::DIRECTION_ALL,
        );

        $this->assertSame('Sum', $sum['metric_label']);
        $this->assertCount(2, $sum['days']);
        $this->assertSame(140.0, $sum['days'][0]['value']);
        $this->assertSame(25.0, $sum['days'][1]['value']);
        $this->assertSame(165.0, $sum['grand_total']);
        $this->assertSame(125.0, $sum['income_total']);
        $this->assertSame(40.0, $sum['outcome_total']);

        $count = BankPaymentsChartReportBuilder::build(
            '2026-09-01',
            '2026-09-30',
            BankPaymentsChartReportBuilder::METRIC_COUNT,
            BankPaymentsChartReportBuilder::DIRECTION_ALL,
        );

        $this->assertSame('Count', $count['metric_label']);
        $this->assertSame(2.0, $count['days'][0]['value']);
        $this->assertSame(1.0, $count['days'][1]['value']);
        $this->assertSame(3.0, $count['grand_total']);
    }

    public function test_builder_filters_by_direction(): void
    {
        $this->actingAsAdmin();

        $this->makeTransaction([
            'booking_date' => '2026-09-05',
            'direction' => BankStatementDirection::Credit,
            'amount' => 80,
        ]);
        $this->makeTransaction([
            'booking_date' => '2026-09-05',
            'direction' => BankStatementDirection::Debit,
            'amount' => 30,
        ]);

        $income = BankPaymentsChartReportBuilder::build(
            '2026-09-01',
            '2026-09-30',
            BankPaymentsChartReportBuilder::METRIC_SUM,
            BankPaymentsChartReportBuilder::DIRECTION_INCOME,
        );

        $this->assertSame(1, $income['transactions']);
        $this->assertSame(80.0, $income['grand_total']);
        $this->assertSame(80.0, $income['income_total']);
        $this->assertSame(0.0, $income['outcome_total']);

        $outcome = BankPaymentsChartReportBuilder::build(
            '2026-09-01',
            '2026-09-30',
            BankPaymentsChartReportBuilder::METRIC_COUNT,
            BankPaymentsChartReportBuilder::DIRECTION_OUTCOME,
        );

        $this->assertSame(1, $outcome['transactions']);
        $this->assertSame(1.0, $outcome['grand_total']);
    }

    public function test_livewire_switches_metric_and_direction(): void
    {
        $this->actingAsAdmin();

        $this->makeTransaction([
            'booking_date' => now()->startOfMonth()->toDateString(),
            'direction' => BankStatementDirection::Credit,
            'amount' => 50,
        ]);

        Livewire::test(ReportsBankPayments::class)
            ->assertSee('Bank payments by day (sum)')
            ->set('data.metric', BankPaymentsChartReportBuilder::METRIC_COUNT)
            ->assertSee('Bank payments by day (count)')
            ->set('data.direction', BankPaymentsChartReportBuilder::DIRECTION_INCOME)
            ->assertSee('Income');
    }

    private function actingAsAdmin(): User
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create(['email' => 'admin-bank-payments@example.com']);
        $admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        return $admin;
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

        $bookingDate = (string) ($overrides['booking_date'] ?? '2026-09-01');

        return BankStatementTransaction::query()->create(array_merge([
            'bank_statement_import_id' => $import->getKey(),
            'fingerprint' => bin2hex(random_bytes(16)),
            'month_key' => substr($bookingDate, 0, 7),
            'booking_date' => $bookingDate,
            'direction' => BankStatementDirection::Credit,
            'amount' => 10,
            'currency' => 'EUR',
            'counterpart_name' => 'Customer UAB',
            'description' => 'Payment',
        ], $overrides));
    }
}
