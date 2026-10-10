<?php

namespace Tests\Unit;

use App\Models\CompanySetting;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\InvoiceCode;
use App\Models\User;
use App\Services\ProfitLossReportDocumentGenerator;
use App\Services\ProfitLossReportPdfGenerator;
use App\Services\ProfitLossStatementBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ProfitLossStatementBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_statement_follows_lithuanian_pnl_layout(): void
    {
        $sales = $this->code('5000', 'Parduotų prekių pajamos', debit: 0, credit: 1000);
        $cost = $this->code('6000', 'Parduotų prekių savikaina', debit: 400, credit: 0);
        $admin = $this->code('6300', 'Nuomos sąnaudos', debit: 150, credit: 0);
        $tax = $this->code('6900', 'Pelno mokestis', debit: 50, credit: 0);

        $statement = ProfitLossStatementBuilder::build(collect([$sales, $cost, $admin, $tax]));

        $this->assertSame(1000.0, $statement['revenue']);
        $this->assertSame(600.0, $statement['expenses']);
        $this->assertSame(600.0, $statement['gross']);
        $this->assertSame(450.0, $statement['operating']);
        $this->assertSame(450.0, $statement['profit_before_tax']);
        $this->assertSame(400.0, $statement['net']);
        $this->assertTrue($statement['is_profit']);

        $byKey = collect($statement['lines'])->keyBy('key');
        $this->assertSame('Pardavimo pajamos', $byKey['sales']['label_lt']);
        $this->assertSame(1000.0, $byKey['sales']['amount']);
        $this->assertSame(400.0, $byKey['cost_of_sales']['amount']);
        $this->assertSame(600.0, $byKey['gross']['amount']);
        $this->assertSame('total', $byKey['net']['style']);
        $this->assertSame(400.0, $byKey['net']['amount']);
    }

    public function test_income_is_credit_minus_debit_and_loss_is_negative_net(): void
    {
        $this->assertSame(80.0, ProfitLossStatementBuilder::netIncome(20, 100));
        $this->assertSame(70.0, ProfitLossStatementBuilder::netExpense(90, 20));

        $cost = $this->code('6001', 'Savikaina', debit: 200, credit: 0);
        $statement = ProfitLossStatementBuilder::build(collect([$cost]));

        $this->assertSame(-200.0, $statement['net']);
        $this->assertFalse($statement['is_profit']);
    }

    public function test_pdf_and_document_type(): void
    {
        CompanySetting::query()->create([
            'company_name' => 'UAB Test',
            'company_id' => '123456789',
        ]);

        $sales = $this->code('50', 'Pardavimo pajamos', debit: 0, credit: 250);

        $payload = ProfitLossReportPdfGenerator::build(collect([$sales]), [
            'period_label' => '2026-08-01 — 2026-08-31',
            'supplier_label' => 'All suppliers',
        ]);

        $this->assertSame('Profit (loss) statement', $payload['title']);
        $this->assertSame('Pelno (nuostolių) ataskaita', $payload['subtitle']);
        $this->assertSame(250.0, $payload['net']);
        $this->assertTrue($payload['isProfit']);

        $pdf = ProfitLossReportPdfGenerator::generate(collect([$sales]), [
            'period_label' => '2026-08-01 — 2026-08-31',
        ]);
        $this->assertStringStartsWith('%PDF', $pdf);

        $type = ProfitLossReportDocumentGenerator::ensureDocumentTypeExists();
        $this->assertSame('Profit (loss) statement', $type->name);
        $this->assertTrue((bool) $type->group_by_year);
        $this->assertSame(
            'Profit (loss) statement — 2026-08-01 — 2026-08-31 — Acme',
            ProfitLossReportDocumentGenerator::documentName([
                'period_label' => '2026-08-01 — 2026-08-31',
                'supplier_label' => 'Acme',
            ]),
        );
        $this->assertSame(
            'profit-loss-statement-20260801-20260831-acme.pdf',
            ProfitLossReportDocumentGenerator::fileName([
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
        $sales = $this->code('5000', 'Pajamos', debit: 0, credit: 10);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SharePoint must be enabled');

        try {
            ProfitLossReportDocumentGenerator::create(
                collect([$sales]),
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
                DocumentType::query()->where('name', 'Profit (loss) statement')->exists(),
            );
        }
    }

    private function code(string $code, string $name, float $debit, float $credit): InvoiceCode
    {
        $model = new InvoiceCode([
            'code' => $code,
            'name' => $name,
        ]);
        $model->turnover_debit = $debit;
        $model->turnover_credit = $credit;

        return $model;
    }
}
