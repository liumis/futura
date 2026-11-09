<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\Invoices;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InvoiceProjectAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_edit_can_assign_and_clear_project(): void
    {
        $admin = $this->actingAsAdmin();
        $contact = Contact::query()->create([
            'company_name' => 'Supplier UAB',
            'company_id' => '100200300',
            'company_address' => 'Vilnius',
        ]);
        $project = Project::query()->create([
            'name' => 'Office fit-out',
            'code' => 'OFF-01',
            'archived' => false,
        ]);
        $invoice = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'INV-PROJ-1',
            'invoice_date' => '2026-11-01',
            'upload_date' => '2026-11-01',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 100,
            'vat' => 21,
            'sum_inc_vat' => 121,
            'payment_term' => 14,
            'paid_amount' => 0,
            'paid' => false,
            'is_draft' => false,
        ]);

        Livewire::test(Invoices::class)
            ->set('filters.month', 'any')
            ->callAction('invoiceDetails', data: [
                'project_id' => $project->getKey(),
            ], arguments: [
                'invoiceId' => $invoice->getKey(),
            ])
            ->assertHasNoActionErrors();

        $this->assertSame($project->getKey(), (int) $invoice->fresh()->project_id);
        $this->assertTrue($project->invoices()->whereKey($invoice->getKey())->exists());

        Livewire::test(Invoices::class)
            ->set('filters.month', 'any')
            ->callAction('invoiceDetails', data: [
                'project_id' => null,
            ], arguments: [
                'invoiceId' => $invoice->getKey(),
            ])
            ->assertHasNoActionErrors();

        $this->assertNull($invoice->fresh()->project_id);
    }

    private function actingAsAdmin(): User
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create(['email' => 'admin-invoice-project@example.com']);
        $admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        return $admin;
    }
}
