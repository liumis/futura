<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\ReportsInvoicesChart;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoicesChartReportBuilder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportsInvoicesChartTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_invoices_chart(): void
    {
        $this->actingAsAdmin();

        Livewire::test(ReportsInvoicesChart::class)
            ->assertOk()
            ->assertSee('Invoices chart')
            ->assertSee('Payment')
            ->assertSee('Late days')
            ->assertSee('Counterpart')
            ->assertSee('Country');
    }

    public function test_builder_groups_paid_partly_and_unpaid_totals(): void
    {
        $admin = $this->actingAsAdmin();

        $lithuania = Contact::query()->create([
            'company_name' => 'LT Customer',
            'company_id' => '111',
            'company_address' => 'Vilnius',
            'company_country' => 'Lithuania',
        ]);
        $germany = Contact::query()->create([
            'company_name' => 'DE Customer',
            'company_id' => '222',
            'company_address' => 'Berlin',
            'company_country' => 'Germany',
        ]);

        $this->makeInvoice($admin, $lithuania, [
            'invoice_number' => 'INV-PAID',
            'invoice_date' => '2026-09-05',
            'sum_inc_vat' => 100,
            'paid_amount' => 100,
            'paid' => true,
            'payment_term' => 14,
        ]);
        $this->makeInvoice($admin, $lithuania, [
            'invoice_number' => 'INV-PART',
            'invoice_date' => '2026-09-10',
            'sum_inc_vat' => 200,
            'paid_amount' => 50,
            'paid' => false,
            'payment_term' => 14,
        ]);
        $this->makeInvoice($admin, $germany, [
            'invoice_number' => 'INV-UNPAID',
            'invoice_date' => '2026-08-01',
            'sum_inc_vat' => 80,
            'paid_amount' => 0,
            'paid' => false,
            'payment_term' => 7,
        ]);

        $all = InvoicesChartReportBuilder::build('2026-08-01', '2026-09-30');

        $this->assertSame(3, $all['invoice_count']);
        $this->assertSame(1, $all['paid_count']);
        $this->assertSame(1, $all['partly_paid_count']);
        $this->assertSame(1, $all['unpaid_count']);
        $this->assertSame(100.0, $all['paid_total']);
        $this->assertSame(200.0, $all['partly_paid_total']);
        $this->assertSame(80.0, $all['unpaid_total']);
        $this->assertSame(230.0, $all['outstanding_total']);
        $this->assertCount(3, $all['days']);
        $this->assertSame('2026-08-01', $all['days'][0]['day']);
        $this->assertSame(80.0, $all['days'][0]['unpaid']);

        $unpaidOnly = InvoicesChartReportBuilder::build(
            '2026-08-01',
            '2026-09-30',
            InvoicesChartReportBuilder::STATUS_UNPAID,
        );
        $this->assertSame(1, $unpaidOnly['invoice_count']);
        $this->assertSame(80.0, $unpaidOnly['unpaid_total']);

        $byCountry = InvoicesChartReportBuilder::build(
            '2026-08-01',
            '2026-09-30',
            InvoicesChartReportBuilder::STATUS_ALL,
            InvoicesChartReportBuilder::LATE_ANY,
            null,
            'Germany',
        );
        $this->assertSame(1, $byCountry['invoice_count']);
        $this->assertSame(80.0, $byCountry['unpaid_total']);

        $byContact = InvoicesChartReportBuilder::build(
            '2026-08-01',
            '2026-09-30',
            InvoicesChartReportBuilder::STATUS_ALL,
            InvoicesChartReportBuilder::LATE_ANY,
            $lithuania->getKey(),
        );
        $this->assertSame(2, $byContact['invoice_count']);

        // INV-UNPAID due 2026-08-08; as of 2026-10-01 => 54 days late
        $late = InvoicesChartReportBuilder::build(
            '2026-08-01',
            '2026-09-30',
            InvoicesChartReportBuilder::STATUS_ALL,
            '30',
            null,
            null,
            '2026-10-01',
        );
        $this->assertSame(1, $late['invoice_count']);
        $this->assertSame(80.0, $late['unpaid_total']);
    }

    public function test_livewire_applies_status_filter(): void
    {
        $admin = $this->actingAsAdmin();
        $contact = Contact::query()->create([
            'company_name' => 'Filter Co',
            'company_id' => '333',
            'company_address' => 'Kaunas',
            'company_country' => 'Lithuania',
        ]);
        $this->makeInvoice($admin, $contact, [
            'invoice_number' => 'INV-LIVE',
            'invoice_date' => now()->startOfMonth()->toDateString(),
            'sum_inc_vat' => 55,
            'paid_amount' => 0,
            'paid' => false,
        ]);

        Livewire::test(ReportsInvoicesChart::class)
            ->assertSee('Invoices by payment status')
            ->assertSee('All months')
            ->assertSee('This month')
            ->assertSee('Last month')
            ->set('data.status', InvoicesChartReportBuilder::STATUS_UNPAID)
            ->assertSee('Unpaid')
            ->set('data.late_days', '1')
            ->assertSee('days late')
            ->set('data.month', 'any')
            ->assertSet('data.from', null)
            ->assertSet('data.until', null);
    }

    private function actingAsAdmin(): User
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create(['email' => 'admin-invoices-chart@example.com']);
        $admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeInvoice(User $admin, Contact $contact, array $overrides = []): Invoice
    {
        $sum = (float) ($overrides['sum_inc_vat'] ?? 100);

        return Invoice::query()->create(array_merge([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'INV-CHART',
            'invoice_date' => '2026-09-01',
            'upload_date' => '2026-09-01',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => round($sum / 1.21, 2),
            'vat' => round($sum - ($sum / 1.21), 2),
            'sum_inc_vat' => $sum,
            'payment_term' => 30,
            'paid_amount' => 0,
            'paid' => false,
        ], $overrides));
    }
}
