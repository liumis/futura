<?php

namespace Tests\Unit;

use App\Models\Contact;
use App\Models\IncomeType;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InvoiceCmrRequirementTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_sale_requires_cmr_and_is_missing_without_documents(): void
    {
        $admin = $this->makeUploader();
        $incomeType = IncomeType::query()->create(['name' => 'Sales']);
        $contact = Contact::query()->create([
            'company_name' => 'Export Customer',
            'company_country' => 'Croatia',
            'company_id' => 'HR123',
            'company_address' => 'Zagreb',
        ]);

        $invoice = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'income_type_id' => $incomeType->getKey(),
            'invoice_number' => 'FTE-CMR-1',
            'invoice_date' => '2026-08-01',
            'upload_date' => '2026-08-24',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 100,
            'vat' => 0,
            'sum_inc_vat' => 100,
            'is_own_account' => false,
        ]);

        $this->assertTrue($invoice->isExportSale());
        $this->assertTrue($invoice->requiresCmrDocument());
        $this->assertTrue($invoice->isMissingCmrDocument());
    }

    public function test_export_sale_with_cmr_attached_is_not_missing(): void
    {
        $admin = $this->makeUploader();
        $incomeType = IncomeType::query()->create(['name' => 'Sales']);
        $contact = Contact::query()->create([
            'company_name' => 'Export Customer',
            'company_country' => 'Germany',
            'company_id' => 'DE123',
            'company_address' => 'Berlin',
        ]);

        $invoice = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'income_type_id' => $incomeType->getKey(),
            'invoice_number' => 'FTE-CMR-2',
            'invoice_date' => '2026-08-01',
            'upload_date' => '2026-08-24',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 100,
            'vat' => 0,
            'sum_inc_vat' => 100,
            'is_own_account' => false,
            'additional_documents' => [
                [
                    'name' => 'cmr.pdf',
                    'original_name' => 'CMR.pdf',
                    'web_url' => 'https://sharepoint.example/cmr.pdf',
                ],
            ],
        ]);

        $this->assertTrue($invoice->requiresCmrDocument());
        $this->assertFalse($invoice->isMissingCmrDocument());
    }

    public function test_lithuanian_sale_does_not_require_cmr(): void
    {
        $admin = $this->makeUploader();
        $incomeType = IncomeType::query()->create(['name' => 'Sales']);
        $contact = Contact::query()->create([
            'company_name' => 'Local UAB',
            'company_country' => 'Lithuania',
            'company_id' => '123456789',
            'company_vat' => 'LT123456789',
            'company_address' => 'Vilnius',
        ]);

        $invoice = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'income_type_id' => $incomeType->getKey(),
            'invoice_number' => 'FTE-LT-1',
            'invoice_date' => '2026-08-01',
            'upload_date' => '2026-08-24',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 100,
            'vat' => 21,
            'sum_inc_vat' => 121,
            'is_own_account' => false,
        ]);

        $this->assertFalse($invoice->isExportSale());
        $this->assertFalse($invoice->requiresCmrDocument());
        $this->assertFalse($invoice->isMissingCmrDocument());
    }

    public function test_expense_invoice_does_not_require_cmr(): void
    {
        $admin = $this->makeUploader();
        $contact = Contact::query()->create([
            'company_name' => 'DSV Road, UAB',
            'company_country' => 'Lithuania',
            'company_id' => '1',
            'company_address' => 'Vilnius',
        ]);

        $invoice = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'EXP-1',
            'invoice_date' => '2026-08-01',
            'upload_date' => '2026-08-24',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 50,
            'vat' => 0,
            'sum_inc_vat' => 50,
            'is_own_account' => false,
        ]);

        $this->assertFalse($invoice->requiresCmrDocument());
    }

    public function test_marked_history_export_does_not_require_cmr(): void
    {
        $admin = $this->makeUploader();
        $incomeType = IncomeType::query()->create(['name' => 'Sales']);
        $contact = Contact::query()->create([
            'company_name' => 'Export Customer',
            'company_country' => 'Croatia',
            'company_id' => 'HR123',
            'company_address' => 'Zagreb',
        ]);

        $invoice = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'income_type_id' => $incomeType->getKey(),
            'invoice_number' => 'FTE-HIST-1',
            'invoice_date' => '2026-03-15',
            'upload_date' => '2026-03-15',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 100,
            'vat' => 0,
            'sum_inc_vat' => 100,
            'is_own_account' => false,
            'is_history' => true,
        ]);

        $this->assertTrue($invoice->isExportSale());
        $this->assertTrue($invoice->isHistoricalRecord());
        $this->assertFalse($invoice->requiresCmrDocument());
        $this->assertFalse($invoice->isMissingCmrDocument());
    }

    private function makeUploader(): User
    {
        Role::findOrCreate('admin');

        return User::factory()->create(['email' => 'cmr-uploader-'.uniqid('', true).'@example.com']);
    }
}
