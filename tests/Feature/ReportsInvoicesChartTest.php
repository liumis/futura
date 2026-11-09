<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\ReportsInvoicesChart;
use App\Models\CompanySetting;
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
            ->assertSee('Group by')
            ->assertSee('Payment due date')
            ->assertSee('Payment')
            ->assertSee('Late days')
            ->assertSee('Counterpart')
            ->assertSee('Country')
            ->assertSee('Still to pay')
            ->assertSee('Due date')
            ->assertSee('Generate SEPA')
            ->assertSee('Pay now');
    }

    public function test_chart_table_can_select_and_generate_partial_sepa(): void
    {
        $iban = 'LT121000011101001000';
        CompanySetting::query()->create([
            'company_name' => 'UAB Futura',
            'company_iban' => $iban,
            'company_bic' => 'AGBLLT2X',
        ]);

        $admin = $this->actingAsAdmin();
        $contact = Contact::query()->create([
            'company_name' => 'Supplier UAB',
            'company_id' => '555',
            'company_address' => 'Vilnius',
            'company_country' => 'Lithuania',
            'bank_account' => $iban,
            'bank_bic' => 'AGBLLT2X',
        ]);
        $invoice = $this->makeInvoice($admin, $contact, [
            'invoice_number' => 'INV-CHART-SEPA',
            'invoice_date' => now()->subDays(10)->toDateString(),
            'sum_inc_vat' => 121,
            'paid_amount' => 21,
            'paid' => false,
            'payment_term' => 30,
        ]);

        $component = Livewire::test(ReportsInvoicesChart::class)
            ->set('data.month', 'any')
            ->set('data.from', null)
            ->set('data.until', null)
            ->set('data.date_basis', InvoicesChartReportBuilder::DATE_BASIS_DUE)
            ->assertSee('INV-CHART-SEPA')
            ->set('selectedInvoiceIds', [(string) $invoice->getKey()])
            ->assertSet('paymentAmounts.'.((string) $invoice->getKey()), 100.0);

        $this->assertSame(1, $component->instance()->selectedPayableCount());

        $component
            ->callAction('makeBankPayment', data: [
                'payments' => [
                    [
                        'invoice_id' => $invoice->getKey(),
                        'amount' => 55.25,
                    ],
                ],
            ])
            ->assertHasNoActionErrors()
            ->assertFileDownloaded();
    }

    public function test_builder_groups_by_payment_due_date_for_cashflow(): void
    {
        $admin = $this->actingAsAdmin();
        $contact = Contact::query()->create([
            'company_name' => 'Cashflow Co',
            'company_id' => '444',
            'company_address' => 'Vilnius',
            'company_country' => 'Lithuania',
        ]);

        // Invoice 2026-09-01 + 14 days => due 2026-09-15
        $this->makeInvoice($admin, $contact, [
            'invoice_number' => 'INV-DUE-UNPAID',
            'invoice_date' => '2026-09-01',
            'sum_inc_vat' => 120,
            'paid_amount' => 0,
            'paid' => false,
            'payment_term' => 14,
        ]);
        // Invoice 2026-09-01 + 30 days => due 2026-10-01 (outside Sep range)
        $this->makeInvoice($admin, $contact, [
            'invoice_number' => 'INV-DUE-OCT',
            'invoice_date' => '2026-09-01',
            'sum_inc_vat' => 50,
            'paid_amount' => 0,
            'paid' => false,
            'payment_term' => 30,
        ]);
        // Paid invoice still lands on due day but not in "still to pay"
        $this->makeInvoice($admin, $contact, [
            'invoice_number' => 'INV-DUE-PAID',
            'invoice_date' => '2026-09-05',
            'sum_inc_vat' => 40,
            'paid_amount' => 40,
            'paid' => true,
            'payment_term' => 10,
        ]);

        $byInvoice = InvoicesChartReportBuilder::build(
            '2026-09-01',
            '2026-09-30',
            InvoicesChartReportBuilder::STATUS_ALL,
            InvoicesChartReportBuilder::LATE_ANY,
            null,
            null,
            null,
            InvoicesChartReportBuilder::DATE_BASIS_INVOICE,
        );
        $this->assertSame(3, $byInvoice['invoice_count']);
        $this->assertSame(InvoicesChartReportBuilder::DATE_BASIS_INVOICE, $byInvoice['date_basis']);

        $byDue = InvoicesChartReportBuilder::build(
            '2026-09-01',
            '2026-09-30',
            InvoicesChartReportBuilder::STATUS_ALL,
            InvoicesChartReportBuilder::LATE_ANY,
            null,
            null,
            null,
            InvoicesChartReportBuilder::DATE_BASIS_DUE,
        );

        $this->assertSame(InvoicesChartReportBuilder::DATE_BASIS_DUE, $byDue['date_basis']);
        $this->assertSame('Invoices by payment due date', $byDue['title']);
        // Oct-due invoice excluded from September due-date window
        $this->assertSame(2, $byDue['invoice_count']);
        $this->assertSame(120.0, $byDue['due_to_pay_total']);
        $this->assertSame(40.0, $byDue['paid_total']);

        // Both remaining invoices due on 2026-09-15 (Sep 1+14 unpaid, Sep 5+10 paid)
        $this->assertCount(1, $byDue['days']);
        $this->assertSame('2026-09-15', $byDue['days'][0]['day']);
        $this->assertSame(120.0, $byDue['days'][0]['due_to_pay']);
        $this->assertSame(40.0, $byDue['days'][0]['paid']);

        $this->assertCount(2, $byDue['rows']);
        $this->assertSame('2026-09-15', $byDue['rows'][0]['due_date']);
        $this->assertSame('2026-09-15', $byDue['rows'][1]['due_date']);
        $numbers = collect($byDue['rows'])->pluck('number')->all();
        $this->assertContains('INV-DUE-UNPAID', $numbers);
        $this->assertContains('INV-DUE-PAID', $numbers);
        $this->assertNotContains('INV-DUE-OCT', $numbers);
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
            ->assertSee('Invoices by payment due date')
            ->assertSee('All months')
            ->assertSee('This month')
            ->assertSee('Last month')
            ->set('data.date_basis', InvoicesChartReportBuilder::DATE_BASIS_INVOICE)
            ->assertSee('Invoices by payment status')
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
