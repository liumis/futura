<?php

namespace Tests\Unit;

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoicePrivateProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_invoice_is_marked_when_contact_is_a_private_provider(): void
    {
        $admin = User::factory()->create();
        $contact = $this->contact(['private_provider' => true]);

        $invoice = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'INV-PP-1',
            'invoice_date' => '2026-08-01',
            'upload_date' => '2026-08-24',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 100,
            'vat' => 21,
            'sum_inc_vat' => 121,
            'private_provider' => false,
        ]);

        $this->assertTrue((bool) $invoice->fresh()->private_provider);
    }

    public function test_new_invoice_stays_unmarked_when_contact_is_not_a_private_provider(): void
    {
        $admin = User::factory()->create();
        $contact = $this->contact();

        $invoice = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'INV-PP-2',
            'invoice_date' => '2026-08-01',
            'upload_date' => '2026-08-24',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 100,
            'vat' => 21,
            'sum_inc_vat' => 121,
            'private_provider' => false,
        ]);

        $this->assertFalse((bool) $invoice->fresh()->private_provider);
    }

    public function test_invoice_can_be_marked_manually_when_contact_is_not_a_private_provider(): void
    {
        $admin = User::factory()->create();
        $contact = $this->contact();

        $invoice = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'INV-PP-3',
            'invoice_date' => '2026-08-01',
            'upload_date' => '2026-08-24',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 100,
            'vat' => 21,
            'sum_inc_vat' => 121,
            'private_provider' => true,
        ]);

        $this->assertTrue((bool) $invoice->fresh()->private_provider);
    }

    public function test_changing_contact_copies_the_private_provider_flag(): void
    {
        $admin = User::factory()->create();
        $plain = $this->contact(['company_name' => 'Plain UAB', 'company_id' => '111']);
        $private = $this->contact(['company_name' => 'Private UAB', 'company_id' => '222', 'private_provider' => true]);

        $invoice = Invoice::query()->create([
            'contact_id' => $plain->getKey(),
            'invoice_number' => 'INV-PP-4',
            'invoice_date' => '2026-08-01',
            'upload_date' => '2026-08-24',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 100,
            'vat' => 21,
            'sum_inc_vat' => 121,
        ]);

        $this->assertFalse((bool) $invoice->fresh()->private_provider);

        $invoice->forceFill(['contact_id' => $private->getKey()])->save();
        $this->assertTrue((bool) $invoice->fresh()->private_provider);

        $invoice->forceFill(['contact_id' => $plain->getKey()])->save();
        $this->assertFalse((bool) $invoice->fresh()->private_provider);
    }

    public function test_invoice_override_sticks_when_contact_does_not_change(): void
    {
        $admin = User::factory()->create();
        $contact = $this->contact(['private_provider' => true]);

        $invoice = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'INV-PP-5',
            'invoice_date' => '2026-08-01',
            'upload_date' => '2026-08-24',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 100,
            'vat' => 21,
            'sum_inc_vat' => 121,
        ]);

        $invoice->forceFill(['private_provider' => false])->save();

        $this->assertFalse((bool) $invoice->fresh()->private_provider);
    }

    public function test_enabling_the_contact_flag_marks_existing_invoices(): void
    {
        $admin = User::factory()->create();
        $contact = $this->contact();

        $invoice = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'INV-PP-6',
            'invoice_date' => '2026-08-01',
            'upload_date' => '2026-08-24',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 100,
            'vat' => 21,
            'sum_inc_vat' => 121,
        ]);

        $this->assertFalse((bool) $invoice->fresh()->private_provider);

        $contact->forceFill(['private_provider' => true])->save();

        $this->assertTrue((bool) $invoice->fresh()->private_provider);
    }

    public function test_disabling_the_contact_flag_does_not_unmark_invoices(): void
    {
        $admin = User::factory()->create();
        $contact = $this->contact(['private_provider' => true]);

        $invoice = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'INV-PP-7',
            'invoice_date' => '2026-08-01',
            'upload_date' => '2026-08-24',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 100,
            'vat' => 21,
            'sum_inc_vat' => 121,
        ]);

        $contact->forceFill(['private_provider' => false])->save();

        $this->assertTrue((bool) $invoice->fresh()->private_provider);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function contact(array $overrides = []): Contact
    {
        return Contact::query()->create(array_merge([
            'company_name' => 'Provider UAB',
            'company_id' => '123456789',
            'company_address' => 'Vilnius',
            'private_provider' => false,
        ], $overrides));
    }
}
