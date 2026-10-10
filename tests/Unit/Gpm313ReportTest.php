<?php

namespace Tests\Unit;

use App\Enums\EmployeeNpdType;
use App\Models\CompanySetting;
use App\Models\Dividend;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeMonthlyPayment;
use App\Models\Shareholder;
use App\Models\User;
use App\Services\Gpm313PdfGenerator;
use App\Services\Gpm313ReportBuilder;
use App\Services\Gpm313ReportDocumentGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

class Gpm313ReportTest extends TestCase
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

    public function test_builder_splits_employment_gpm_by_payment_day_and_includes_dividends(): void
    {
        $early = $this->makeEmployee('Jonas', 'Ankstyvas');
        $late = $this->makeEmployee('Ona', 'Velyva');
        $cancelled = $this->makeEmployee('Atmesta', 'Mokejimas');

        EmployeeMonthlyPayment::query()->create([
            'employee_id' => $early->getKey(),
            'payment_date' => '2026-07-10',
            'gross_amount' => 5000,
            'gpm_amount' => 800,
            'status' => 'open',
        ]);
        EmployeeMonthlyPayment::query()->create([
            'employee_id' => $late->getKey(),
            'payment_date' => '2026-07-31',
            'gross_amount' => 4878.99,
            'gpm_amount' => 1175.80,
            'status' => 'payed',
        ]);
        EmployeeMonthlyPayment::query()->create([
            'employee_id' => $cancelled->getKey(),
            'payment_date' => '2026-07-05',
            'gross_amount' => 9999,
            'gpm_amount' => 2000,
            'status' => 'wrong',
        ]);
        EmployeeMonthlyPayment::query()->create([
            'employee_id' => $early->getKey(),
            'payment_date' => '2026-06-30',
            'gross_amount' => 4000,
            'gpm_amount' => 700,
            'status' => 'payed',
        ]);

        $shareholder = Shareholder::query()->create([
            'name' => 'Investor',
            'shareholder_percentage' => 100,
        ]);
        Dividend::query()->create([
            'shareholder_id' => $shareholder->getKey(),
            'date' => '2026-07-20',
            'amount' => 1000,
            'status' => 'open',
        ]);

        $report = Gpm313ReportBuilder::build(2026, 7);

        $this->assertSame('UAB FUTURA TEXTILES EUROPE', $report['company_name']);
        $this->assertSame('306740042', $report['company_id']);
        $this->assertSame(2026, $report['year']);
        $this->assertSame(7, $report['month']);
        $this->assertSame('2026 liepa', $report['period_label']);
        $this->assertSame(9878.99, $report['field5']);
        $this->assertSame(800.0, $report['field6']);
        $this->assertSame(1175.8, $report['field7']);
        $this->assertSame(0.0, $report['field8']);
        $this->assertSame(0.0, $report['field9']);
        $this->assertSame(0.0, $report['field10']);
        $this->assertSame(1000.0, $report['field11']);
        $this->assertSame(200.0, $report['field12']);
        $this->assertSame(2, $report['employment_count']);
        $this->assertSame(1, $report['dividend_count']);
        $this->assertSame('9 878,99', $report['field5_label']);
    }

    public function test_pdf_is_branded_and_contains_form_code(): void
    {
        $payload = Gpm313PdfGenerator::build(2026, 7);

        $this->assertSame('306740042', implode('', array_map('trim', $payload['companyIdCells'])));
        $this->assertSame(['2', '0', '2', '6'], $payload['yearCells']);
        $this->assertContains('7', $payload['monthCells']);
        $this->assertArrayHasKey('logoDataUri', $payload);

        $pdf = Gpm313PdfGenerator::generate(2026, 7);
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_document_type_and_file_name(): void
    {
        $type = Gpm313ReportDocumentGenerator::ensureDocumentTypeExists();

        $this->assertSame('GPM313 deklaracija', $type->name);
        $this->assertTrue((bool) $type->group_by_year);
        $this->assertSame(
            $type->getKey(),
            Gpm313ReportDocumentGenerator::ensureDocumentTypeExists()->getKey(),
        );
        $this->assertSame(
            'GPM313 deklaracija — 2026 liepa',
            Gpm313ReportDocumentGenerator::documentName(['period_label' => '2026 liepa']),
        );
        $this->assertSame('gpm313-2026-07.pdf', Gpm313ReportDocumentGenerator::fileName(2026, 7));
    }

    public function test_create_requires_sharepoint_and_does_not_leave_document(): void
    {
        $user = User::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SharePoint must be enabled');

        try {
            Gpm313ReportDocumentGenerator::create(2026, 7, $user);
        } finally {
            $this->assertSame(0, Document::query()->count());
            $this->assertTrue(
                DocumentType::query()->where('name', 'GPM313 deklaracija')->exists(),
            );
        }
    }

    public function test_amount_cells_use_lithuanian_decimal_comma(): void
    {
        $cells = Gpm313PdfGenerator::amountCells(9878.99);

        $this->assertSame(',', $cells[8]);
        $this->assertSame('9', $cells[9]);
        $this->assertSame('9', $cells[10]);
        $this->assertSame('9878,99', trim(implode('', $cells)));
    }

    private function makeEmployee(string $name, string $surname): Employee
    {
        return Employee::query()->create([
            'name' => $name,
            'surname' => $surname,
            'working_time_percentage' => 100,
            'npd_type' => EmployeeNpdType::Standard,
        ]);
    }
}
