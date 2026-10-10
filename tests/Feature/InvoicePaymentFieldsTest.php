<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\Invoices;
use App\Filament\Admin\Pages\NewInvoice;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Money;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InvoicePaymentFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_term_is_copied_from_customer(): void
    {
        $customer = User::factory()->create(['payment_term' => 21]);

        $this->assertSame(21, Invoice::paymentTermFromCustomer($customer));
        $this->assertSame(Invoice::DEFAULT_PAYMENT_TERM_DAYS, Invoice::paymentTermFromCustomer(null));
    }

    public function test_paid_is_auto_checked_when_paid_amount_equals_invoice_sum(): void
    {
        $admin = $this->actingAsAdmin();
        $contact = Contact::query()->create([
            'company_name' => 'Customer UAB',
            'company_id' => '123456789',
            'company_address' => 'Vilnius',
        ]);

        $invoice = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'INV-PAY-1',
            'invoice_date' => '2026-08-01',
            'upload_date' => '2026-08-24',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 100,
            'vat' => 21,
            'sum_inc_vat' => 121,
            'payment_term' => 14,
            'paid_amount' => 121,
            'paid' => false,
        ]);

        $invoice = $invoice->fresh();

        $this->assertTrue((bool) $invoice->paid);
        $this->assertSame('121.00', (string) $invoice->paid_amount);
        $this->assertSame(14, (int) $invoice->payment_term);
    }

    public function test_partial_paid_amount_does_not_mark_invoice_paid(): void
    {
        $admin = $this->actingAsAdmin();
        $contact = Contact::query()->create([
            'company_name' => 'Customer UAB',
            'company_id' => '123456789',
            'company_address' => 'Vilnius',
        ]);

        $invoice = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'INV-PAY-2',
            'invoice_date' => '2026-08-01',
            'upload_date' => '2026-08-24',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 100,
            'vat' => 21,
            'sum_inc_vat' => 121,
            'paid_amount' => 50,
            'paid' => false,
        ]);

        $this->assertFalse((bool) $invoice->fresh()->paid);
    }

    public function test_invoices_list_shows_payment_term_paid_amount_and_paid(): void
    {
        $admin = $this->actingAsAdmin();
        $contact = Contact::query()->create([
            'company_name' => 'Customer UAB',
            'company_id' => '123456789',
            'company_address' => 'Vilnius',
        ]);

        Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'INV-PAY-3',
            'invoice_date' => '2026-08-01',
            'upload_date' => '2026-08-24',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 100,
            'vat' => 21,
            'sum_inc_vat' => 121,
            'payment_term' => 30,
            'paid_amount' => 121,
            'paid' => true,
        ]);

        Livewire::test(Invoices::class)
            ->set('filters.month', 'any')
            ->assertOk()
            ->assertSee('Paid amount')
            ->assertSee('Paid')
            ->assertSee(Money::format(121))
            ->assertSee('Invoice summary')
            ->assertSee('Outstanding');
    }

    public function test_create_form_includes_payment_fields(): void
    {
        $this->actingAsAdmin();

        Livewire::test(NewInvoice::class)
            ->assertOk()
            ->assertFormFieldExists('payment_term')
            ->assertFormFieldExists('paid_amount')
            ->assertFormFieldExists('paid')
            ->assertFormFieldExists('currency')
            ->assertSet('data.currency', 'EUR');
    }

    public function test_duplicate_invoice_number_is_rejected_without_server_error(): void
    {
        $admin = $this->actingAsAdmin();
        $contact = Contact::query()->create([
            'company_name' => 'DSV Road UAB',
            'company_id' => '111552645',
            'company_address' => 'Vilnius',
        ]);
        Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => '1025402',
            'invoice_date' => '2026-08-31',
            'upload_date' => '2026-09-16',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 1439.84,
            'vat' => 302.37,
            'sum_inc_vat' => 1742.21,
        ]);

        Livewire::test(NewInvoice::class)
            ->assertOk()
            ->fillForm([
                'contact_id' => $contact->getKey(),
                'invoice_number' => '1025402',
                'invoice_date' => '2026-08-31',
                'sum_without_vat' => 1439.84,
                'vat' => 302.37,
                'sum_inc_vat' => 1742.21,
                'upload_date' => '2026-09-16',
                'uploaded_by' => $admin->getKey(),
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Invoice already exists');

        $this->assertSame(1, Invoice::query()->where('invoice_number', '1025402')->count());
    }

    public function test_invoice_defaults_to_eur_and_can_store_another_currency(): void
    {
        $admin = $this->actingAsAdmin();
        $contact = Contact::query()->create([
            'company_name' => 'Customer UAB',
            'company_id' => '123456789',
            'company_address' => 'Vilnius',
        ]);

        $eurInvoice = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'INV-EUR-1',
            'invoice_date' => '2026-08-01',
            'upload_date' => '2026-09-16',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 100,
            'vat' => 21,
            'sum_inc_vat' => 121,
        ]);

        $usdInvoice = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'INV-USD-1',
            'invoice_date' => '2026-08-01',
            'upload_date' => '2026-09-16',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 80,
            'vat' => 0,
            'sum_inc_vat' => 80,
            'currency' => 'usd',
        ]);

        $this->assertSame('EUR', $eurInvoice->fresh()?->currency);
        $this->assertSame('USD', $usdInvoice->fresh()?->currency);

        Livewire::test(Invoices::class)
            ->set('filters.month', 'any')
            ->assertOk()
            ->assertSee(Money::format(80, 2, 'USD'))
            ->assertSee(Money::format(100));
    }

    private function actingAsAdmin(): User
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create(['email' => 'admin-invoice-payment@example.com']);
        $admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        return $admin;
    }
}
