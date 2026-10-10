<?php

namespace Tests\Unit;

use App\Models\CompanySetting;
use App\Models\Contact;
use App\Models\Dividend;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Invoice;
use App\Models\Shareholder;
use App\Models\User;
use App\Services\Gpm312PdfGenerator;
use App\Services\Gpm312ReportBuilder;
use App\Services\Gpm312ReportDocumentGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

class Gpm312ReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-26 12:00:00'));
        CompanySetting::query()->create([
            'company_name' => 'UAB FUTURA TEXTILES EUROPE',
            'company_id' => '306740042',
            'company_address' => 'Vilnius',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_builder_aggregates_dividends_by_shareholder_for_the_year(): void
    {
        $jonas = Shareholder::query()->create([
            'name' => 'Jonas Jonaitis',
            'shareholder_percentage' => 60,
        ]);
        $ona = Shareholder::query()->create([
            'name' => 'Ona Onaitė',
            'shareholder_percentage' => 40,
        ]);

        Dividend::query()->create([
            'shareholder_id' => $jonas->getKey(),
            'date' => '2026-03-10',
            'amount' => 1000,
            'status' => 'open',
        ]);
        Dividend::query()->create([
            'shareholder_id' => $jonas->getKey(),
            'date' => '2026-09-01',
            'amount' => 500,
            'status' => 'payed',
        ]);
        Dividend::query()->create([
            'shareholder_id' => $ona->getKey(),
            'date' => '2026-06-15',
            'amount' => 2000,
            'status' => 'payed',
        ]);
        Dividend::query()->create([
            'shareholder_id' => $jonas->getKey(),
            'date' => '2026-04-01',
            'amount' => 9999,
            'status' => 'wrong',
        ]);
        Dividend::query()->create([
            'shareholder_id' => $ona->getKey(),
            'date' => '2025-12-31',
            'amount' => 800,
            'status' => 'payed',
        ]);

        $report = Gpm312ReportBuilder::build(2026);

        $this->assertSame('UAB FUTURA TEXTILES EUROPE', $report['company_name']);
        $this->assertSame('306740042', $report['company_id']);
        $this->assertSame(2026, $report['year']);
        $this->assertSame(3500.0, $report['field4']);
        $this->assertSame(700.0, $report['field5']);
        $this->assertSame(0.0, $report['field6']);
        $this->assertSame(0.0, $report['field7']);
        $this->assertSame(3, $report['dividend_count']);
        $this->assertSame(2, $report['shareholder_count']);
        $this->assertSame('3 500,00', $report['field4_label']);

        $byName = collect($report['lines'])->keyBy('name');
        $this->assertSame(1500.0, $byName['Jonas Jonaitis']['l8']);
        $this->assertSame(300.0, $byName['Jonas Jonaitis']['l10']);
        $this->assertSame('26', $byName['Jonas Jonaitis']['code']);
        $this->assertSame('A', $byName['Jonas Jonaitis']['class']);
        $this->assertSame(20.0, $byName['Jonas Jonaitis']['l9']);
        $this->assertSame(2000.0, $byName['Ona Onaitė']['l8']);
        $this->assertSame(400.0, $byName['Ona Onaitė']['l10']);
        $this->assertSame(2, $report['line_count']);
        $this->assertSame(0, $report['invoice_count']);
    }

    public function test_builder_includes_private_provider_invoices_for_the_year(): void
    {
        $admin = User::factory()->create();
        $provider = Contact::query()->create([
            'company_name' => 'Jonas Individuali veikla',
            'company_id' => '39001112222',
            'company_address' => 'Kaunas',
            'private_provider' => true,
        ]);
        $other = Contact::query()->create([
            'company_name' => 'Supplier UAB',
            'company_id' => '111',
            'company_address' => 'Vilnius',
        ]);

        $shareholder = Shareholder::query()->create([
            'name' => 'Ona Onaitė',
            'shareholder_percentage' => 100,
        ]);
        Dividend::query()->create([
            'shareholder_id' => $shareholder->getKey(),
            'date' => '2026-06-15',
            'amount' => 2000,
            'status' => 'payed',
        ]);

        Invoice::query()->create([
            'contact_id' => $provider->getKey(),
            'invoice_number' => 'PP-1',
            'invoice_date' => '2026-03-10',
            'upload_date' => '2026-03-10',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 400,
            'vat' => 0,
            'sum_inc_vat' => 400,
            'private_provider' => true,
        ]);
        Invoice::query()->create([
            'contact_id' => $provider->getKey(),
            'invoice_number' => 'PP-2',
            'invoice_date' => '2026-11-01',
            'upload_date' => '2026-11-01',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 150,
            'vat' => 0,
            'sum_inc_vat' => 150,
        ]);
        Invoice::query()->create([
            'contact_id' => $other->getKey(),
            'invoice_number' => 'SKIP-1',
            'invoice_date' => '2026-04-01',
            'upload_date' => '2026-04-01',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 999,
            'vat' => 0,
            'sum_inc_vat' => 999,
        ]);
        Invoice::query()->create([
            'contact_id' => $provider->getKey(),
            'invoice_number' => 'PP-OLD',
            'invoice_date' => '2025-12-31',
            'upload_date' => '2025-12-31',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 800,
            'vat' => 0,
            'sum_inc_vat' => 800,
        ]);

        $report = Gpm312ReportBuilder::build(2026);

        $this->assertSame(2550.0, $report['field4']);
        $this->assertSame(400.0, $report['field5']);
        $this->assertSame(1, $report['dividend_count']);
        $this->assertSame(1, $report['shareholder_count']);
        $this->assertSame(2, $report['invoice_count']);
        $this->assertSame(1, $report['provider_count']);
        $this->assertSame(2, $report['line_count']);

        $byName = collect($report['lines'])->keyBy('name');
        $this->assertSame(2000.0, $byName['Ona Onaitė']['l8']);
        $this->assertSame('26', $byName['Ona Onaitė']['code']);
        $this->assertSame('invoice', $byName['Jonas Individuali veikla']['source']);
        $this->assertSame('53', $byName['Jonas Individuali veikla']['code']);
        $this->assertSame('B', $byName['Jonas Individuali veikla']['class']);
        $this->assertSame(550.0, $byName['Jonas Individuali veikla']['l8']);
        $this->assertSame(0.0, $byName['Jonas Individuali veikla']['l10']);
    }

    public function test_pdf_builds_for_the_year(): void
    {
        $payload = Gpm312PdfGenerator::build(2026);

        $this->assertSame(['2', '0', '2', '6'], $payload['yearCells']);
        $this->assertArrayHasKey('logoDataUri', $payload);

        $pdf = Gpm312PdfGenerator::generate(2026);
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_document_type_and_file_name(): void
    {
        $type = Gpm312ReportDocumentGenerator::ensureDocumentTypeExists();

        $this->assertSame('GPM312 deklaracija', $type->name);
        $this->assertTrue((bool) $type->group_by_year);
        $this->assertSame(
            $type->getKey(),
            Gpm312ReportDocumentGenerator::ensureDocumentTypeExists()->getKey(),
        );
        $this->assertSame(
            'GPM312 deklaracija — 2026',
            Gpm312ReportDocumentGenerator::documentName(['period_label' => '2026']),
        );
        $this->assertSame('gpm312-2026.pdf', Gpm312ReportDocumentGenerator::fileName(2026));
    }

    public function test_create_requires_sharepoint_and_does_not_leave_document(): void
    {
        $user = User::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SharePoint must be enabled');

        try {
            Gpm312ReportDocumentGenerator::create(2026, $user);
        } finally {
            $this->assertSame(0, Document::query()->count());
            $this->assertTrue(
                DocumentType::query()->where('name', 'GPM312 deklaracija')->exists(),
            );
        }
    }
}
