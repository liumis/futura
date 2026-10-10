<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\Contacts\Pages\EditContact;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ContactDeleteGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_contact_unlinks_invoices_instead_of_blocking(): void
    {
        $admin = $this->actingAsAdmin();
        $contact = Contact::query()->create([
            'company_name' => 'Blocked Co',
            'company_id' => 'BLK-1',
            'company_address' => 'Vilnius',
        ]);

        $invoice = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'INV-BLK-1',
            'invoice_date' => '2026-08-01',
            'upload_date' => '2026-08-24',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 10,
            'vat' => 0,
            'sum_inc_vat' => 10,
        ]);

        Livewire::test(EditContact::class, ['record' => $contact->getKey()])
            ->callAction('delete');

        $this->assertDatabaseMissing('contacts', ['id' => $contact->getKey()]);
        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->getKey(),
            'contact_id' => null,
            'invoice_number' => 'INV-BLK-1',
        ]);
    }

    public function test_contact_without_invoices_can_be_deleted(): void
    {
        $this->actingAsAdmin();
        $contact = Contact::query()->create([
            'company_name' => 'Free Co',
            'company_id' => 'FREE-1',
            'company_address' => 'Vilnius',
        ]);

        Livewire::test(EditContact::class, ['record' => $contact->getKey()])
            ->callAction('delete');

        $this->assertDatabaseMissing('contacts', ['id' => $contact->getKey()]);
    }

    private function actingAsAdmin(): User
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create(['email' => 'admin-contact-delete@example.com']);
        $admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        return $admin;
    }
}
