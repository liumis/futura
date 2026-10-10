<?php

namespace Tests\Unit;

use App\Models\CompanySetting;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\ExpenseType;
use App\Models\IncomeType;
use App\Models\Invoice;
use App\Models\User;
use App\Models\VatRate;
use App\Services\PvmReportBuilder;
use App\Services\PvmReportDocumentGenerator;
use App\Services\PvmReportPdfGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PvmReportBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_payable_is_output_vat_minus_input_vat(): void
    {
        [$sales, $purchase, $unclassified] = $this->invoices();

        $summary = PvmReportBuilder::build(collect([$sales, $purchase, $unclassified]));

        $this->assertSame(100.0, $summary['sales_base']);
        $this->assertSame(21.0, $summary['output_vat']);
        $this->assertSame(50.0, $summary['purchase_base']);
        $this->assertSame(10.5, $summary['input_vat']);
        $this->assertSame(2.1, $summary['unclassified_vat']);
        $this->assertSame(10.5, $summary['payable']);
        $this->assertTrue($summary['is_payable']);
        $this->assertSame(3, $summary['invoices']);
        $this->assertSame('sales', PvmReportBuilder::direction($sales));
        $this->assertSame('purchases', PvmReportBuilder::direction($purchase));
        $this->assertSame('unclassified', PvmReportBuilder::direction($unclassified));
    }

    public function test_pdf_and_document_type(): void
    {
        CompanySetting::query()->create([
            'company_name' => 'UAB Test',
            'company_id' => '123456789',
        ]);

        [$sales, $purchase] = $this->invoices();

        $payload = PvmReportPdfGenerator::build(collect([$sales, $purchase]), [
            'period_label' => '2026-08-01 — 2026-08-31',
            'company_label' => 'All companies',
            'direction_label' => 'All invoices',
        ]);

        $this->assertSame('PVM report', $payload['title']);
        $this->assertSame('Pridėtinės vertės mokestis', $payload['subtitle']);
        $this->assertSame(10.5, $payload['payable']);
        $this->assertTrue($payload['isPayable']);
        $this->assertCount(2, $payload['lines']);

        $pdf = PvmReportPdfGenerator::generate(collect([$sales, $purchase]), [
            'period_label' => '2026-08-01 — 2026-08-31',
        ]);
        $this->assertStringStartsWith('%PDF', $pdf);

        $type = PvmReportDocumentGenerator::ensureDocumentTypeExists();
        $this->assertSame('PVM report', $type->name);
        $this->assertTrue((bool) $type->group_by_year);
        $this->assertSame(
            'PVM report — 2026-08-01 — 2026-08-31 — Sales (output VAT) — Acme',
            PvmReportDocumentGenerator::documentName([
                'period_label' => '2026-08-01 — 2026-08-31',
                'direction_label' => 'Sales (output VAT)',
                'company_label' => 'Acme',
            ]),
        );
        $this->assertSame(
            'pvm-report-all-20260801-20260831.pdf',
            PvmReportDocumentGenerator::fileName([
                'from' => '2026-08-01',
                'until' => '2026-08-31',
                'direction' => 'all',
            ]),
        );
    }

    public function test_create_requires_sharepoint_and_does_not_leave_document(): void
    {
        CompanySetting::query()->create(['company_name' => 'UAB Test']);
        $user = User::factory()->create();
        [$sales] = $this->invoices();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SharePoint must be enabled');

        try {
            PvmReportDocumentGenerator::create(
                collect([$sales]),
                [
                    'from' => '2026-08-01',
                    'until' => '2026-08-31',
                    'period_label' => '2026-08-01 — 2026-08-31',
                    'company_label' => 'All companies',
                    'direction_label' => 'All invoices',
                ],
                $user,
            );
        } finally {
            $this->assertSame(0, Document::query()->count());
            $this->assertTrue(
                DocumentType::query()->where('name', 'PVM report')->exists(),
            );
        }
    }

    /**
     * @return list<Invoice>
     */
    private function invoices(): array
    {
        $user = User::factory()->create();
        $contact = Contact::query()->create([
            'company_name' => 'Supplier UAB',
            'company_id' => '555',
            'company_address' => 'Vilnius',
        ]);
        $rate = VatRate::query()->create([
            'classificator' => 'PVM1',
            'rate' => 21,
        ]);
        $income = IncomeType::query()->create(['name' => 'Sales']);
        $expense = ExpenseType::query()->create(['name' => 'Purchases']);

        $sales = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'PVM-S-1',
            'invoice_date' => '2026-08-10',
            'upload_date' => '2026-08-10',
            'uploaded_by' => $user->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 100,
            'vat' => 21,
            'sum_inc_vat' => 121,
            'vat_rate_id' => $rate->getKey(),
            'income_type_id' => $income->getKey(),
        ]);

        $purchase = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'PVM-P-1',
            'invoice_date' => '2026-08-12',
            'upload_date' => '2026-08-12',
            'uploaded_by' => $user->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 50,
            'vat' => 10.50,
            'sum_inc_vat' => 60.50,
            'vat_rate_id' => $rate->getKey(),
            'expense_type_id' => $expense->getKey(),
        ]);

        $unclassified = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'PVM-U-1',
            'invoice_date' => '2026-08-14',
            'upload_date' => '2026-08-14',
            'uploaded_by' => $user->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 10,
            'vat' => 2.10,
            'sum_inc_vat' => 12.10,
            'vat_rate_id' => $rate->getKey(),
        ]);

        return [$sales->fresh(['contact', 'vatRate']), $purchase->fresh(['contact', 'vatRate']), $unclassified->fresh(['contact', 'vatRate'])];
    }
}
