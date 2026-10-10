<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\Invoices;
use App\Filament\Admin\Pages\NewInvoice;
use App\Filament\Admin\Resources\Contacts\Pages\CreateContact;
use App\Filament\Admin\Resources\Contacts\Pages\ListContacts;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\OriginCountry;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PrivateProviderFlagTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_form_and_list_include_private_provider(): void
    {
        $this->actingAsAdmin();
        $country = OriginCountry::query()->first()?->name ?? OriginCountry::query()->create(['name' => 'Lithuania'])->name;

        Livewire::test(CreateContact::class)
            ->assertOk()
            ->assertFormFieldExists('private_provider')
            ->fillForm([
                'company_name' => 'Jonas Jonaitis',
                'company_country' => $country,
                'company_id' => '39000000000',
                'company_address' => 'Vilnius',
                'private_provider' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('contacts', [
            'company_name' => 'Jonas Jonaitis',
            'private_provider' => 1,
        ]);

        Livewire::test(ListContacts::class)
            ->assertOk()
            ->assertSee('Private provider')
            ->assertSee('Jonas Jonaitis');
    }

    public function test_invoice_form_and_list_include_private_provider(): void
    {
        $admin = $this->actingAsAdmin();
        $contact = Contact::query()->create([
            'company_name' => 'Private provider',
            'company_id' => '123',
            'company_address' => 'Vilnius',
            'private_provider' => true,
        ]);

        Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'INV-PP-UI',
            'invoice_date' => '2026-08-01',
            'upload_date' => '2026-08-24',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 100,
            'vat' => 21,
            'sum_inc_vat' => 121,
        ]);

        Livewire::test(NewInvoice::class)
            ->assertOk()
            ->assertFormFieldExists('private_provider');

        Livewire::test(Invoices::class)
            ->assertOk()
            ->assertSee('Private provider')
            ->assertSee('INV-PP-UI');
    }

    private function actingAsAdmin(): User
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create(['email' => 'admin-private-provider@example.com']);
        $admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        return $admin;
    }
}
