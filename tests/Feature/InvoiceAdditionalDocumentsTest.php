<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\Invoices;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InvoiceAdditionalDocumentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoices_list_shows_additional_document_original_name(): void
    {
        $admin = $this->actingAsAdmin();
        $contact = Contact::query()->create([
            'company_name' => 'Customer UAB',
            'company_id' => '123456789',
            'company_address' => 'Vilnius',
        ]);

        Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'INV-CMR-1',
            'invoice_date' => '2026-08-01',
            'upload_date' => '2026-08-24',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 100,
            'vat' => 21,
            'sum_inc_vat' => 121,
            'additional_documents' => [
                [
                    'name' => '1-2026-08-01-additional-CMR.pdf',
                    'original_name' => 'CMR.pdf',
                    'path' => 'invoices/2026/2026-08/1-2026-08-01-additional-CMR.pdf',
                    'item_id' => 'item-1',
                    'web_url' => 'https://sharepoint.example/cmr.pdf',
                    'uploaded_at' => now()->toIso8601String(),
                ],
            ],
        ]);

        Livewire::test(Invoices::class)
            ->assertOk()
            ->assertSee('CMR')
            ->assertSee('CMR.pdf')
            ->assertSee('https://sharepoint.example/cmr.pdf')
            ->assertSee('Add more')
            ->assertDontSee('1-2026-08-01-additional-CMR.pdf');
    }

    public function test_invoices_list_shows_add_when_invoice_has_no_additional_documents(): void
    {
        $admin = $this->actingAsAdmin();
        $contact = Contact::query()->create([
            'company_name' => 'Customer UAB',
            'company_id' => '123456789',
            'company_address' => 'Vilnius',
        ]);
        $invoice = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'INV-EMPTY-1',
            'invoice_date' => '2026-08-01',
            'upload_date' => '2026-08-24',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 100,
            'vat' => 21,
            'sum_inc_vat' => 121,
        ]);

        Livewire::test(Invoices::class)
            ->assertOk()
            ->assertSee('CMR')
            ->assertSeeHtml("mountAction('uploadAdditionalDocuments'")
            ->call('mountAction', 'uploadAdditionalDocuments', ['invoiceId' => $invoice->getKey()])
            ->assertSet('mountedActions.0.name', 'uploadAdditionalDocuments')
            ->assertSet('mountedActions.0.arguments.invoiceId', $invoice->getKey());
    }

    public function test_invoice_prefers_original_name_for_additional_document_label(): void
    {
        $invoice = new Invoice([
            'additional_documents' => [
                [
                    'name' => '9-2026-08-03-additional-uuid.pdf',
                    'original_name' => 'CMR.pdf',
                    'path' => 'invoices/2026/2026-08/9-2026-08-03-additional-uuid.pdf',
                    'web_url' => 'https://sharepoint.example/cmr.pdf',
                ],
            ],
        ]);

        $file = $invoice->additionalDocuments()[0];

        $this->assertSame('CMR.pdf', $invoice->additionalDocumentLabel($file));
        $this->assertSame('https://sharepoint.example/cmr.pdf', $invoice->additionalDocumentUrl($file));
    }

    private function actingAsAdmin(): User
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create(['email' => 'admin-invoice-additional@example.com']);
        $admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        return $admin;
    }
}
