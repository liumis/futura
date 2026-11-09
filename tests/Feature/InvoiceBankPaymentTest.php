<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\Invoices;
use App\Models\CompanySetting;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InvoiceBankPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_has_select_all_and_make_bank_payment(): void
    {
        $admin = $this->actingAsAdmin();
        $contact = Contact::query()->create([
            'company_name' => 'Supplier UAB',
            'company_id' => '123',
            'company_address' => 'Vilnius',
        ]);
        $first = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'SF-1',
            'invoice_date' => '2026-08-01',
            'upload_date' => '2026-08-01',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 100,
            'vat' => 21,
            'sum_inc_vat' => 121,
        ]);
        $second = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'SF-2',
            'invoice_date' => '2026-08-02',
            'upload_date' => '2026-08-02',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 50,
            'vat' => 10.5,
            'sum_inc_vat' => 60.5,
        ]);

        $component = Livewire::test(Invoices::class)
            ->set('filters.month', 'any')
            ->assertOk()
            ->assertSee('Generate SEPA')
            ->assertSee('SF-1')
            ->assertSee('SF-2')
            ->call('toggleSelectAllInvoices')
            ->assertSet('selectAllInvoices', true);

        $this->assertEqualsCanonicalizing(
            [(string) $first->getKey(), (string) $second->getKey()],
            $component->get('selectedInvoiceIds'),
        );
    }

    public function test_make_bank_payment_without_selection_notifies(): void
    {
        $this->actingAsAdmin();

        Livewire::test(Invoices::class)
            ->assertActionDisabled('makeBankPayment');
    }

    public function test_selecting_invoice_fills_pay_now_amount_for_partial_edit(): void
    {
        $admin = $this->actingAsAdmin();
        $contact = Contact::query()->create([
            'company_name' => 'Supplier UAB',
            'company_id' => '123',
            'company_address' => 'Vilnius',
        ]);
        $invoice = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'SF-PAY-NOW',
            'invoice_date' => '2026-08-01',
            'upload_date' => '2026-08-01',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 100,
            'vat' => 21,
            'sum_inc_vat' => 121,
            'paid_amount' => 21,
            'paid' => false,
        ]);

        Livewire::test(Invoices::class)
            ->set('filters.month', 'any')
            ->assertSee('Pay now')
            ->set('selectedInvoiceIds', [(string) $invoice->getKey()])
            ->assertSet('paymentAmounts.'.((string) $invoice->getKey()), 100.0)
            ->set('paymentAmounts.'.((string) $invoice->getKey()), 40.5)
            ->assertSet('paymentAmounts.'.((string) $invoice->getKey()), 40.5);

        $rows = Livewire::test(Invoices::class)
            ->set('filters.month', 'any')
            ->set('selectedInvoiceIds', [(string) $invoice->getKey()])
            ->set('paymentAmounts.'.((string) $invoice->getKey()), 40.5)
            ->instance()
            ->sepaPaymentFormRows();

        $this->assertSame(40.5, (float) $rows[0]['amount']);
        $this->assertSame(100.0, (float) $rows[0]['outstanding']);
    }

    public function test_generate_sepa_modal_defaults_to_outstanding_and_allows_partial(): void
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
            'company_id' => '123',
            'company_address' => 'Vilnius',
            'bank_account' => $iban,
            'bank_bic' => 'AGBLLT2X',
        ]);
        $invoice = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'SF-SEPA-1',
            'invoice_date' => '2026-08-01',
            'upload_date' => '2026-08-01',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 100,
            'vat' => 21,
            'sum_inc_vat' => 121,
            'paid_amount' => 21,
            'paid' => false,
        ]);

        $component = Livewire::test(Invoices::class)
            ->set('filters.month', 'any')
            ->set('selectedInvoiceIds', [(string) $invoice->getKey()]);

        $rows = $component->instance()->sepaPaymentFormRows();
        $this->assertCount(1, $rows);
        $this->assertSame((int) $invoice->getKey(), (int) $rows[0]['invoice_id']);
        $this->assertSame(100.0, (float) $rows[0]['outstanding']);
        $this->assertSame(100.0, (float) $rows[0]['amount']);

        $component
            ->callAction('makeBankPayment', data: [
                'payments' => [
                    [
                        'invoice_id' => $invoice->getKey(),
                        'amount' => 40.5,
                    ],
                ],
            ])
            ->assertHasNoActionErrors()
            ->assertFileDownloaded();
    }

    public function test_mass_mark_as_paid_updates_selected_invoices(): void
    {
        $admin = $this->actingAsAdmin();
        $contact = Contact::query()->create([
            'company_name' => 'Supplier UAB',
            'company_id' => '123',
            'company_address' => 'Vilnius',
        ]);
        $first = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'SF-PAID-1',
            'invoice_date' => '2026-08-01',
            'upload_date' => '2026-08-01',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 100,
            'vat' => 21,
            'sum_inc_vat' => 121,
            'paid' => false,
            'paid_amount' => 0,
        ]);
        $second = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'SF-PAID-2',
            'invoice_date' => '2026-08-02',
            'upload_date' => '2026-08-02',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 50,
            'vat' => 10.5,
            'sum_inc_vat' => 60.5,
            'paid' => false,
            'paid_amount' => 10,
        ]);

        Livewire::test(Invoices::class)
            ->set('filters.month', 'any')
            ->set('selectedInvoiceIds', [(string) $first->getKey(), (string) $second->getKey()])
            ->callAction('massMarkAsPaid')
            ->assertNotified('Marked as paid');

        $first->refresh();
        $second->refresh();

        $this->assertTrue($first->isFullyPaid());
        $this->assertSame(121.0, (float) $first->paid_amount);
        $this->assertTrue($second->isFullyPaid());
        $this->assertSame(60.5, (float) $second->paid_amount);
    }

    private function actingAsAdmin(): User
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create(['email' => 'admin-invoice-sepa@example.com']);
        $admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        return $admin;
    }
}
