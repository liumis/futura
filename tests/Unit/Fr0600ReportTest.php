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
use App\Services\Fr0600PdfGenerator;
use App\Services\Fr0600ReportBuilder;
use App\Services\Fr0600ReportDocumentGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

class Fr0600ReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-26 12:00:00'));
        CompanySetting::query()->create([
            'company_name' => 'UAB FUTURA TEXTILES EUROPE',
            'company_id' => '306740042',
            'company_vat' => 'LT306740042',
            'company_address' => 'Vilnius',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_builder_maps_sales_and_purchases_to_fr0600_fields(): void
    {
        $this->makeInvoice('sales', 'PVM1', 21, 100, 21, '2026-07-10');
        $this->makeInvoice('sales', 'PVM2', 12, 50, 6, '2026-07-12');
        $this->makeInvoice('sales', 'PVM4', 0, 80, 0, '2026-07-14');
        $this->makeInvoice('purchases', 'PVM1', 21, 40, 8.40, '2026-07-20');
        $this->makeInvoice('sales', 'PVM1', 21, 10, 2.10, '2026-06-30');

        $report = Fr0600ReportBuilder::build(2026, 7);

        $this->assertSame('UAB FUTURA TEXTILES EUROPE', $report['company_name']);
        $this->assertSame('306740042', $report['company_id']);
        $this->assertSame('LT306740042', $report['company_vat']);
        $this->assertSame('2026 liepa', $report['period_label']);
        $this->assertSame(150.0, $report['fields']['11']);
        $this->assertSame(80.0, $report['fields']['18']);
        $this->assertSame(21.0, $report['fields']['29']);
        $this->assertSame(6.0, $report['fields']['29a']);
        $this->assertSame(8.4, $report['fields']['25']);
        $this->assertSame(8.4, $report['fields']['35']);
        $this->assertSame(18.6, $report['fields']['36']);
        $this->assertSame(3, $report['sales_count']);
        $this->assertSame(1, $report['purchase_count']);
        $this->assertSame('18,60', $report['labels']['36']);
    }

    public function test_pdf_builds_for_the_month(): void
    {
        $payload = Fr0600PdfGenerator::build(2026, 7);

        $this->assertSame(['2', '0', '2', '6'], $payload['yearCells']);
        $this->assertContains('7', $payload['monthCells']);
        $this->assertArrayHasKey('logoDataUri', $payload);

        $pdf = Fr0600PdfGenerator::generate(2026, 7);
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_document_type_and_file_name(): void
    {
        $type = Fr0600ReportDocumentGenerator::ensureDocumentTypeExists();

        $this->assertSame('FR0600 deklaracija', $type->name);
        $this->assertTrue((bool) $type->group_by_year);
        $this->assertSame(
            'FR0600 deklaracija — 2026 liepa',
            Fr0600ReportDocumentGenerator::documentName(['period_label' => '2026 liepa']),
        );
        $this->assertSame('fr0600-2026-07.pdf', Fr0600ReportDocumentGenerator::fileName(2026, 7));
    }

    public function test_create_requires_sharepoint_and_does_not_leave_document(): void
    {
        $user = User::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SharePoint must be enabled');

        try {
            Fr0600ReportDocumentGenerator::create(2026, 7, $user);
        } finally {
            $this->assertSame(0, Document::query()->count());
            $this->assertTrue(
                DocumentType::query()->where('name', 'FR0600 deklaracija')->exists(),
            );
        }
    }

    private function makeInvoice(
        string $direction,
        string $classificator,
        float $rate,
        float $base,
        float $vat,
        string $date,
    ): Invoice {
        static $user = null;
        static $contact = null;
        static $income = null;
        static $expense = null;
        static $rates = [];

        $user ??= User::factory()->create();
        $contact ??= Contact::query()->create([
            'company_name' => 'Partner UAB',
            'company_id' => '111',
            'company_address' => 'Vilnius',
        ]);
        $income ??= IncomeType::query()->create(['name' => 'Sales']);
        $expense ??= ExpenseType::query()->create(['name' => 'Purchases']);
        $rates[$classificator] ??= VatRate::query()->create([
            'classificator' => $classificator,
            'rate' => $rate,
        ]);

        return Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'FR-'.$classificator.'-'.$date,
            'invoice_date' => $date,
            'upload_date' => $date,
            'uploaded_by' => $user->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => $base,
            'vat' => $vat,
            'sum_inc_vat' => round($base + $vat, 2),
            'vat_rate_id' => $rates[$classificator]->getKey(),
            'income_type_id' => $direction === 'sales' ? $income->getKey() : null,
            'expense_type_id' => $direction === 'purchases' ? $expense->getKey() : null,
        ]);
    }
}
