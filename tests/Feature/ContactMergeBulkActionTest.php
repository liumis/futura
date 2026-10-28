<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\Contacts\Pages\ListContacts;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ContactMergeBulkActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_merge_bulk_action_moves_invoices_and_deletes_sources(): void
    {
        $user = $this->actingAsAdmin();

        $keep = Contact::query()->create([
            'company_name' => 'Provider Keep',
            'company_id' => 'KEEP-1',
            'company_address' => 'Vilnius',
            'company_email' => 'keep@example.com',
        ]);
        $drop = Contact::query()->create([
            'company_name' => 'Provider Drop',
            'company_id' => 'DROP-1',
            'company_address' => 'Kaunas',
            'company_phone' => '+37060000000',
        ]);

        Invoice::query()->create([
            'contact_id' => $drop->id,
            'invoice_number' => 'MERGE-1',
            'invoice_date' => '2026-09-01',
            'sum_without_vat' => 50,
            'vat' => 0,
            'sum_inc_vat' => 50,
            'upload_date' => '2026-09-01',
            'uploaded_by' => $user->id,
        ]);

        Livewire::test(ListContacts::class)
            ->assertOk()
            ->callTableBulkAction('mergeContacts', [$keep, $drop], data: [
                'target_id' => (string) $keep->id,
            ])
            ->assertHasNoTableBulkActionErrors();

        $this->assertNull(Contact::query()->find($drop->id));
        $keep->refresh();
        $this->assertSame('+37060000000', $keep->company_phone);
        $this->assertSame(1, Invoice::query()->where('contact_id', $keep->id)->count());
        $this->assertSame(0, Invoice::query()->whereNull('contact_id')->count());
    }

    private function actingAsAdmin(): User
    {
        Role::findOrCreate(User::ROLE_ADMIN, 'web');
        $user = User::factory()->create(['email' => 'admin-contact-merge@example.com']);
        $user->assignRole(User::ROLE_ADMIN);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($user);

        return $user;
    }
}
