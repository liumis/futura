<?php

namespace Tests\Unit;

use App\Models\CompanySetting;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\InvoiceCode;
use App\Models\User;
use App\Services\TurnoverSheetCalculator;
use App\Services\TurnoverSheetDocumentGenerator;
use App\Services\TurnoverSheetPdfGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class TurnoverSheetCalculatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_split_balance_shows_one_side_only(): void
    {
        $this->assertSame(
            ['debit' => 25.5, 'credit' => 0.0],
            TurnoverSheetCalculator::splitBalance(40.5, 15.0),
        );
        $this->assertSame(
            ['debit' => 0.0, 'credit' => 10.0],
            TurnoverSheetCalculator::splitBalance(5.0, 15.0),
        );
        $this->assertSame(
            ['debit' => 0.0, 'credit' => 0.0],
            TurnoverSheetCalculator::splitBalance(10.0, 10.0),
        );
    }

    public function test_closing_follows_opening_plus_turnover(): void
    {
        $row = TurnoverSheetCalculator::forAccount(
            openingDebitSum: 100,
            openingCreditSum: 0,
            turnoverDebit: 20,
            turnoverCredit: 40,
        );

        $this->assertSame(100.0, $row['opening_debit']);
        $this->assertSame(0.0, $row['opening_credit']);
        $this->assertSame(20.0, $row['turnover_debit']);
        $this->assertSame(40.0, $row['turnover_credit']);
        $this->assertSame(80.0, $row['closing_debit']);
        $this->assertSame(0.0, $row['closing_credit']);
    }

    public function test_credit_opening_can_flip_to_debit_closing(): void
    {
        $row = TurnoverSheetCalculator::forAccount(0, 50, 80, 10);

        $this->assertSame(0.0, $row['opening_debit']);
        $this->assertSame(50.0, $row['opening_credit']);
        $this->assertSame(20.0, $row['closing_debit']);
        $this->assertSame(0.0, $row['closing_credit']);
    }

    public function test_pdf_build_and_document_type(): void
    {
        CompanySetting::query()->create([
            'company_name' => 'UAB Test',
            'company_id' => '123456789',
        ]);

        $code = new InvoiceCode(['code' => '1', 'name' => 'ILGALAIKIS TURTAS']);
        $code->opening_debit = 100;
        $code->opening_credit = 0;
        $code->turnover_debit = 20;
        $code->turnover_credit = 40;

        $payload = TurnoverSheetPdfGenerator::build(collect([$code]), [
            'period_label' => '2026-08-01 — 2026-08-31',
            'supplier_label' => 'All suppliers',
        ]);

        $this->assertSame('Turnover sheet', $payload['title']);
        $this->assertSame('Didžioji apskaitos knyga', $payload['subtitle']);
        $this->assertSame(100.0, $payload['lines'][0]['opening_debit_raw']);
        $this->assertSame(80.0, $payload['lines'][0]['closing_debit_raw']);
        $this->assertSame(20.0, $payload['turnoverDebitTotalRaw']);
        $this->assertSame(40.0, $payload['turnoverCreditTotalRaw']);
        $this->assertFalse($payload['turnoverBalanced']);

        $pdf = TurnoverSheetPdfGenerator::generate(collect([$code]), [
            'period_label' => '2026-08-01 — 2026-08-31',
        ]);
        $this->assertStringStartsWith('%PDF', $pdf);

        $type = TurnoverSheetDocumentGenerator::ensureDocumentTypeExists();
        $this->assertSame('Turnover sheet', $type->name);
        $this->assertTrue((bool) $type->group_by_year);
        $this->assertSame(
            'Turnover sheet — 2026-08-01 — 2026-08-31 — Acme',
            TurnoverSheetDocumentGenerator::documentName([
                'period_label' => '2026-08-01 — 2026-08-31',
                'supplier_label' => 'Acme',
            ]),
        );
        $this->assertSame(
            'turnover-sheet-20260801-20260831-acme.pdf',
            TurnoverSheetDocumentGenerator::fileName([
                'from' => '2026-08-01',
                'until' => '2026-08-31',
                'supplier_label' => 'Acme',
            ]),
        );
    }

    public function test_create_requires_sharepoint_and_does_not_leave_document(): void
    {
        CompanySetting::query()->create(['company_name' => 'UAB Test']);
        $user = User::factory()->create();
        $code = InvoiceCode::query()->create([
            'code' => '1',
            'name' => 'ILGALAIKIS TURTAS',
        ]);
        $code->opening_debit = 10;
        $code->opening_credit = 0;
        $code->turnover_debit = 0;
        $code->turnover_credit = 0;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SharePoint must be enabled');

        try {
            TurnoverSheetDocumentGenerator::create(
                collect([$code]),
                [
                    'from' => '2026-08-01',
                    'until' => '2026-08-31',
                    'period_label' => '2026-08-01 — 2026-08-31',
                    'supplier_label' => 'All suppliers',
                ],
                $user,
            );
        } finally {
            $this->assertSame(0, Document::query()->count());
            $this->assertTrue(
                DocumentType::query()->where('name', 'Turnover sheet')->exists(),
            );
        }
    }
}
