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
use App\Services\BalanceReportDocumentGenerator;
use App\Services\BalanceReportPdfGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class BalanceReportDocumentGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_pdf_groups_lines_by_account_and_computes_balance(): void
    {
        CompanySetting::query()->create([
            'company_name' => 'UAB Test',
            'company_id' => '123456789',
        ]);

        $assets = new InvoiceCode(['code' => '1', 'name' => 'ILGALAIKIS TURTAS']);
        $assets->debit = 15.5;
        $assets->credit = 5.0;
        $assets->line_count = 2;

        $equity = new InvoiceCode(['code' => '3', 'name' => 'NUOSAVAS KAPITALAS']);
        $equity->debit = 0;
        $equity->credit = 10.5;
        $equity->line_count = 1;

        $payload = BalanceReportPdfGenerator::build(collect([$assets, $equity]), [
            'period_label' => '2026-04-03 — 2026-08-31',
            'supplier_label' => 'Futura Textiles Europe, UAB',
        ]);

        $this->assertSame('Balance', $payload['title']);
        $this->assertSame('Balansas', $payload['subtitle']);
        $this->assertSame('2026-04-03 — 2026-08-31', $payload['periodLabel']);
        $this->assertCount(2, $payload['lines']);
        $this->assertSame('1', $payload['lines'][0]['code']);
        $this->assertSame('ILGALAIKIS TURTAS', $payload['lines'][0]['name']);
        $this->assertSame(2, $payload['lines'][0]['line_count']);
        $this->assertSame(10.5, $payload['lines'][0]['balance_raw']);
        $this->assertSame(15.5, $payload['debitTotalRaw']);
        $this->assertSame(15.5, $payload['creditTotalRaw']);
        $this->assertSame(0.0, $payload['balanceTotalRaw']);
        $this->assertTrue($payload['balanced']);

        $pdf = BalanceReportPdfGenerator::generate(collect([$assets, $equity]), [
            'period_label' => '2026-04-03 — 2026-08-31',
            'supplier_label' => 'All suppliers',
        ]);

        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_document_type_and_file_name(): void
    {
        $type = BalanceReportDocumentGenerator::ensureDocumentTypeExists();

        $this->assertSame('Balance report', $type->name);
        $this->assertTrue((bool) $type->group_by_year);
        $this->assertSame(
            $type->getKey(),
            BalanceReportDocumentGenerator::ensureDocumentTypeExists()->getKey(),
        );

        $this->assertSame(
            'Balance report — 2026-04-03 — 2026-08-31 — Acme',
            BalanceReportDocumentGenerator::documentName([
                'period_label' => '2026-04-03 — 2026-08-31',
                'supplier_label' => 'Acme',
            ]),
        );

        $this->assertSame(
            'balance-report-20260403-20260831-acme.pdf',
            BalanceReportDocumentGenerator::fileName([
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
        $this->makeFinanceLine();

        $code = InvoiceCode::query()->first();
        $code->debit = 10;
        $code->credit = 0;
        $code->line_count = 1;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SharePoint must be enabled');

        try {
            BalanceReportDocumentGenerator::create(
                collect([$code]),
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
                DocumentType::query()->where('name', 'Balance report')->exists(),
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
            'debit' => 10,
            'credit' => 0,
            'sort_order' => 1,
        ], $overrides));
    }
}
