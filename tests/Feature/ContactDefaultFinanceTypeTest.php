<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\NewInvoice;
use App\Filament\Admin\Resources\Contacts\Pages\CreateContact;
use App\Filament\Admin\Resources\Contacts\Pages\ListContacts;
use App\Models\Contact;
use App\Models\ExpenseType;
use App\Models\Invoice;
use App\Models\OriginCountry;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ContactDefaultFinanceTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_form_includes_default_service_category(): void
    {
        $this->actingAsAdmin();
        $country = OriginCountry::query()->first()?->name ?? OriginCountry::query()->create(['name' => 'Lithuania'])->name;
        $transport = ExpenseType::query()->firstOrCreate(['name' => 'Transportas']);

        Livewire::test(CreateContact::class)
            ->assertOk()
            ->assertFormFieldExists('default_finance_type')
            ->fillForm([
                'company_name' => 'DSV Road UAB',
                'company_country' => $country,
                'company_id' => '111552645',
                'company_address' => 'Vilnius',
                'default_finance_type' => 'expense:'.$transport->getKey(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('contacts', [
            'company_name' => 'DSV Road UAB',
            'default_finance_type' => 'expense:'.$transport->getKey(),
        ]);
    }

    public function test_prefill_known_defaults_sets_transportas_for_dsv(): void
    {
        $transport = ExpenseType::query()->firstOrCreate(['name' => 'Transportas']);
        $contact = Contact::query()->create([
            'company_name' => 'DSV Road UAB',
            'company_id' => '111552645',
            'company_address' => 'Vilnius',
        ]);

        $this->assertSame(1, Contact::prefillKnownDefaultCategories());
        $this->assertSame('expense:'.$transport->getKey(), $contact->fresh()?->default_finance_type);
    }

    public function test_new_invoice_selecting_contact_prefills_category(): void
    {
        $this->actingAsAdmin();
        $transport = ExpenseType::query()->firstOrCreate(['name' => 'Transportas']);
        $contact = Contact::query()->create([
            'company_name' => 'DSV Road UAB',
            'company_id' => '111552645',
            'company_address' => 'Vilnius',
            'default_finance_type' => 'expense:'.$transport->getKey(),
        ]);

        Livewire::test(NewInvoice::class)
            ->assertOk()
            ->fillForm([
                'contact_id' => $contact->getKey(),
            ])
            ->assertSet('data.finance_type', 'expense:'.$transport->getKey());
    }

    public function test_contacts_list_shows_default_category(): void
    {
        $this->actingAsAdmin();
        $transport = ExpenseType::query()->firstOrCreate(['name' => 'Transportas']);
        Contact::query()->create([
            'company_name' => 'DSV Road UAB',
            'company_id' => '111552645',
            'company_address' => 'Vilnius',
            'default_finance_type' => 'expense:'.$transport->getKey(),
        ]);

        Livewire::test(ListContacts::class)
            ->assertOk()
            ->assertSee('Default category')
            ->assertSee('Transportas')
            ->assertSee('DSV Road UAB');
    }

    public function test_setting_invoice_category_updates_provider_default(): void
    {
        $admin = User::factory()->create();
        $transport = ExpenseType::query()->firstOrCreate(['name' => 'Transportas']);
        $it = ExpenseType::query()->firstOrCreate(['name' => 'IT']);
        $contact = Contact::query()->create([
            'company_name' => 'Anthropic, PBC',
            'company_id' => 'ANTHROPIC',
            'company_address' => 'San Francisco',
            'default_finance_type' => 'expense:'.$transport->getKey(),
        ]);

        $invoice = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'KUBTJ8F9-0017',
            'invoice_date' => '2026-08-01',
            'upload_date' => '2026-08-02',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 10.28,
            'vat' => 0,
            'sum_inc_vat' => 10.28,
            'expense_type_id' => $transport->getKey(),
        ]);

        $this->assertTrue($invoice->applyFinanceCategory('expense:'.$it->getKey()));
        $this->assertSame('expense:'.$it->getKey(), $contact->fresh()?->default_finance_type);
        $this->assertSame((int) $it->getKey(), (int) $invoice->fresh()?->expense_type_id);
    }

    public function test_first_invoice_remembers_category_as_contact_default(): void
    {
        $admin = User::factory()->create();
        $transport = ExpenseType::query()->firstOrCreate(['name' => 'Transportas']);
        $contact = Contact::query()->create([
            'company_name' => 'New Carrier UAB',
            'company_id' => '999000111',
            'company_address' => 'Vilnius',
        ]);

        $invoice = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'INV-FIRST-CAT',
            'invoice_date' => '2026-09-01',
            'upload_date' => '2026-09-16',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 100,
            'vat' => 21,
            'sum_inc_vat' => 121,
            'expense_type_id' => $transport->getKey(),
        ]);

        $this->assertTrue($contact->rememberDefaultCategoryFromInvoice($invoice));
        $this->assertSame('expense:'.$transport->getKey(), $contact->fresh()?->default_finance_type);
    }

    public function test_later_invoice_category_overwrites_provider_default(): void
    {
        $admin = User::factory()->create();
        $transport = ExpenseType::query()->firstOrCreate(['name' => 'Transportas']);
        $other = ExpenseType::query()->firstOrCreate(['name' => 'Paslaugos']);
        $contact = Contact::query()->create([
            'company_name' => 'DSV Road UAB',
            'company_id' => '111552645',
            'company_address' => 'Vilnius',
            'default_finance_type' => 'expense:'.$transport->getKey(),
        ]);

        $invoice = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'INV-SECOND-CAT',
            'invoice_date' => '2026-09-02',
            'upload_date' => '2026-09-16',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 50,
            'vat' => 0,
            'sum_inc_vat' => 50,
            'expense_type_id' => $other->getKey(),
        ]);

        $this->assertTrue($contact->rememberDefaultCategoryFromInvoice($invoice));
        $this->assertSame('expense:'.$other->getKey(), $contact->fresh()?->default_finance_type);
    }

    public function test_provider_default_prefills_missing_invoice_category(): void
    {
        $admin = User::factory()->create();
        $it = ExpenseType::query()->firstOrCreate(['name' => 'IT']);
        $contact = Contact::query()->create([
            'company_name' => 'Anthropic, PBC',
            'company_id' => 'ANTHROPIC',
            'company_address' => 'San Francisco',
            'default_finance_type' => 'expense:'.$it->getKey(),
        ]);

        $invoice = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'KUBTJ8F9-0018',
            'invoice_date' => '2026-08-03',
            'upload_date' => '2026-08-03',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 10,
            'vat' => 0,
            'sum_inc_vat' => 10,
        ]);

        $this->assertTrue($invoice->applySuggestedCategoryIfMissing());
        $this->assertSame((int) $it->getKey(), (int) $invoice->fresh()?->expense_type_id);
    }

    public function test_invoice_without_category_does_not_set_default(): void
    {
        $admin = User::factory()->create();
        $contact = Contact::query()->create([
            'company_name' => 'Unknown Supplier',
            'company_id' => '555',
            'company_address' => 'Kaunas',
        ]);

        $invoice = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'INV-NO-CAT',
            'invoice_date' => '2026-09-03',
            'upload_date' => '2026-09-16',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 10,
            'vat' => 0,
            'sum_inc_vat' => 10,
        ]);

        $this->assertFalse($contact->rememberDefaultCategoryFromInvoice($invoice));
        $this->assertNull($contact->fresh()?->default_finance_type);
    }

    private function actingAsAdmin(): User
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create(['email' => 'admin-contact-category@example.com']);
        $admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        return $admin;
    }
}
