<?php

namespace Tests\Unit;

use App\Models\CompanySetting;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Invoice;
use App\Models\InvoiceCode;
use App\Models\InvoiceFinanceLine;
use App\Models\User;
use App\Services\DebitCreditReportDocumentGenerator;
use App\Services\DebitCreditReportPdfGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DebitCreditReportDocumentGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_pdf_build_includes_line_totals_and_filters(): void
    {
        CompanySetting::query()->create([
            'company_name' => 'UAB Test',
            'company_id' => '123456789',
        ]);

        $line = $this->makeFinanceLine([
            'name' => 'ILGALAIKIS TURTAS',
            'debit' => 10.5,
            'credit' => 0,
        ]);

        $payload = DebitCreditReportPdfGenerator::build(collect([$line]), [
            'period_label' => '2026-04-03 — 2026-08-31',
            'supplier_label' => 'Futura Textiles Europe, UAB',
        ]);

        $this->assertSame('Debit/credit report', $payload['title']);
        $this->assertSame('2026-04-03 — 2026-08-31', $payload['periodLabel']);
        $this->assertSame('Futura Textiles Europe, UAB', $payload['supplierLabel']);
        $this->assertCount(1, $payload['lines']);
        $this->assertSame('FT1000001', $payload['lines'][0]['invoice_number']);
        $this->assertSame('ILGALAIKIS TURTAS', $payload['lines'][0]['name']);
        $this->assertSame('1', $payload['lines'][0]['code']);
        $this->assertSame(10.5, $payload['debitTotalRaw']);
        $this->assertSame(0.0, $payload['creditTotalRaw']);
        $this->assertFalse($payload['balanced']);

        $pdf = DebitCreditReportPdfGenerator::generate(collect([$line]), [
            'period_label' => '2026-04-03 — 2026-08-31',
            'supplier_label' => 'All suppliers',
        ]);

        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_document_type_and_file_name(): void
    {
        $type = DebitCreditReportDocumentGenerator::ensureDocumentTypeExists();

        $this->assertSame('Debit/credit report', $type->name);
        $this->assertTrue((bool) $type->group_by_year);
        $this->assertSame(
            $type->getKey(),
            DebitCreditReportDocumentGenerator::ensureDocumentTypeExists()->getKey(),
        );

        $this->assertSame(
            'Debit/credit report — 2026-04-03 — 2026-08-31 — Acme',
            DebitCreditReportDocumentGenerator::documentName([
                'period_label' => '2026-04-03 — 2026-08-31',
                'supplier_label' => 'Acme',
            ]),
        );

        $this->assertSame(
            'debit-credit-report-20260403-20260831-acme.pdf',
            DebitCreditReportDocumentGenerator::fileName([
                'from' => '2026-04-03',
                'until' => '2026-08-31',
                'supplier_label' => 'Acme',
            ]),
        );
    }

    public function test_create_requires_sharepoint_and_does_not_leave_document(): void
    {
        CompanySetting::query()->create(['company_name' => 'UAB Test']);
        $user = User::factory()->create();
        $line = $this->makeFinanceLine();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SharePoint must be enabled');

        try {
            DebitCreditReportDocumentGenerator::create(
                collect([$line]),
                [
                    'from' => '2026-04-03',
                    'until' => '2026-08-31',
                    'period_label' => '2026-04-03 — 2026-08-31',
                    'supplier_label' => 'All suppliers',
                ],
                $user,
            );
        } finally {
            $this->assertSame(0, Document::query()->count());
            $this->assertTrue(
                DocumentType::query()->where('name', 'Debit/credit report')->exists(),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeFinanceLine(array $overrides = []): InvoiceFinanceLine
    {
        $user = User::factory()->create();
        $contact = Contact::query()->create([
            'company_name' => 'Futura Textiles Europe, UAB',
            'company_id' => '111',
            'company_address' => 'Vilnius',
        ]);
        $code = InvoiceCode::query()->create([
            'code' => '1',
            'name' => 'ILGALAIKIS TURTAS',
        ]);
        $invoice = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'FT1000001',
            'invoice_date' => '2026-07-03',
            'upload_date' => '2026-08-24',
            'uploaded_by' => $user->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 10,
            'vat' => 0,
            'sum_inc_vat' => 10,
        ]);

        return InvoiceFinanceLine::query()->create(array_merge([
            'invoice_id' => $invoice->getKey(),
            'invoice_code_id' => $code->getKey(),
            'name' => 'ILGALAIKIS TURTAS',
            'debit' => 0,
            'credit' => 0,
            'sort_order' => 1,
        ], $overrides));
    }
}
