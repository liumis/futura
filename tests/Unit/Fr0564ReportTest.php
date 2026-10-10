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
use App\Services\Fr0564PdfGenerator;
use App\Services\Fr0564ReportBuilder;
use App\Services\Fr0564ReportDocumentGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

class Fr0564ReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-26 12:00:00'));
        CompanySetting::query()->create([
            'company_name' => 'UAB FUTURA TEXTILES EUROPE',
            'company_id' => '306740042',
            'company_vat' => 'LT100017651210',
            'company_address' => 'Vilnius',
            'company_email' => 'info@example.com',
            'company_phone' => '+37060000000',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_builder_aggregates_intra_community_sales_and_strips_vat_prefix(): void
    {
        $portugal = $this->makeContact('PT Buyer', 'Portugal', 'PT509331858');
        $ireland = $this->makeContact('IE Buyer', 'Ireland', 'IE9689962L');
        $lithuania = $this->makeContact('LT Buyer', 'Lithuania', 'LT100000000');
        $local = $this->makeContact('Local 21%', 'Portugal', 'PT111111111');

        $this->makeInvoice($portugal, 'PVM4', 0, 1000, '2026-07-05');
        $this->makeInvoice($portugal, 'PVM4', 0, 1275, '2026-07-18');
        $this->makeInvoice($ireland, 'PVM4', 0, 10738, '2026-07-20');
        $this->makeInvoice($lithuania, 'PVM4', 0, 5000, '2026-07-21');
        $this->makeInvoice($local, 'PVM1', 21, 800, '2026-07-22');
        $this->makeInvoice($portugal, 'PVM4', 0, 50, '2026-06-30');

        $report = Fr0564ReportBuilder::build(2026, 7);

        $this->assertSame('UAB FUTURA TEXTILES EUROPE', $report['company_name']);
        $this->assertSame('306740042', $report['company_id']);
        $this->assertSame('LT100017651210', $report['company_vat']);
        $this->assertSame('2026 liepa', $report['period_label']);
        $this->assertSame(3, $report['invoice_count']);
        $this->assertSame(2, $report['line_count']);
        $this->assertSame(2275 + 10738, $report['field15']);
        $this->assertSame($report['field15'], $report['field16']);

        $this->assertSame('IE', $report['lines'][0]['country']);
        $this->assertSame('9689962L', $report['lines'][0]['vat_number']);
        $this->assertSame(10738, $report['lines'][0]['goods_int']);

        $this->assertSame('PT', $report['lines'][1]['country']);
        $this->assertSame('509331858', $report['lines'][1]['vat_number']);
        $this->assertSame(2275, $report['lines'][1]['goods_int']);
    }

    public function test_builder_uses_country_when_vat_has_no_prefix(): void
    {
        $contact = $this->makeContact('CZ Buyer', 'Czech Republic', '26900882');
        $this->makeInvoice($contact, 'PVM4', 0, 7161, '2026-07-10');

        $report = Fr0564ReportBuilder::build(2026, 7);

        $this->assertSame(1, $report['line_count']);
        $this->assertSame('CZ', $report['lines'][0]['country']);
        $this->assertSame('26900882', $report['lines'][0]['vat_number']);
        $this->assertSame(7161, $report['lines'][0]['goods_int']);
    }

    public function test_builder_skips_lines_without_vat_or_eu_country(): void
    {
        $noVat = $this->makeContact('No VAT', 'Portugal', null);
        $usa = $this->makeContact('US Buyer', 'United States', 'US123456789');

        $this->makeInvoice($noVat, 'PVM4', 0, 100, '2026-07-10');
        $this->makeInvoice($usa, 'PVM4', 0, 200, '2026-07-11');

        $report = Fr0564ReportBuilder::build(2026, 7);

        $this->assertSame(0, $report['line_count']);
        $this->assertSame(0, $report['field15']);
    }

    public function test_pdf_builds_for_the_month(): void
    {
        $contact = $this->makeContact('PT Buyer', 'Portugal', 'PT509331858');
        $this->makeInvoice($contact, 'PVM4', 0, 2275, '2026-07-05');

        $payload = Fr0564PdfGenerator::build(2026, 7);

        $this->assertSame(['2', '0', '2', '6'], $payload['yearCells']);
        $this->assertContains('7', $payload['monthCells']);
        $this->assertSame(['L', 'T'], $payload['companyVatCountryCells']);
        $this->assertSame('100017651210', implode('', array_map(
            fn (string $cell): string => $cell === ' ' ? '' : $cell,
            $payload['companyVatNumberCells'],
        )));
        $this->assertArrayHasKey('logoDataUri', $payload);

        $pdf = Fr0564PdfGenerator::generate(2026, 7);
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_document_type_and_file_name(): void
    {
        $type = Fr0564ReportDocumentGenerator::ensureDocumentTypeExists();

        $this->assertSame('FR0564 deklaracija', $type->name);
        $this->assertTrue((bool) $type->group_by_year);
        $this->assertSame(
            'FR0564 deklaracija — 2026 liepa',
            Fr0564ReportDocumentGenerator::documentName(['period_label' => '2026 liepa']),
        );
        $this->assertSame('fr0564-2026-07.pdf', Fr0564ReportDocumentGenerator::fileName(2026, 7));
    }

    public function test_create_requires_sharepoint_and_does_not_leave_document(): void
    {
        $user = User::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SharePoint must be enabled');

        try {
            Fr0564ReportDocumentGenerator::create(2026, 7, $user);
        } finally {
            $this->assertSame(0, Document::query()->count());
            $this->assertTrue(
                DocumentType::query()->where('name', 'FR0564 deklaracija')->exists(),
            );
        }
    }

    private function makeContact(string $name, string $country, ?string $vat): Contact
    {
        return Contact::query()->create([
            'company_name' => $name,
            'company_country' => $country,
            'company_id' => '111',
            'company_vat' => $vat,
            'company_address' => $country,
        ]);
    }

    private function makeInvoice(
        Contact $contact,
        string $classificator,
        float $rate,
        float $base,
        string $date,
        string $direction = 'sales',
    ): Invoice {
        $user = User::query()->first() ?? User::factory()->create();
        $income = IncomeType::query()->firstOrCreate(['name' => 'Sales']);
        $expense = ExpenseType::query()->firstOrCreate(['name' => 'Purchases']);
        $vatRate = VatRate::query()->firstOrCreate(
            ['classificator' => $classificator],
            ['rate' => $rate],
        );

        $vat = round($base * ($rate / 100), 2);

        return Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'IC-'.$classificator.'-'.$date.'-'.$contact->getKey(),
            'invoice_date' => $date,
            'upload_date' => $date,
            'uploaded_by' => $user->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => $base,
            'vat' => $vat,
            'sum_inc_vat' => round($base + $vat, 2),
            'vat_rate_id' => $vatRate->getKey(),
            'income_type_id' => $direction === 'sales' ? $income->getKey() : null,
            'expense_type_id' => $direction === 'purchases' ? $expense->getKey() : null,
        ]);
    }
}
