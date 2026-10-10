<?php

namespace Tests\Unit;

use App\Enums\BankStatementDirection;
use App\Services\BankStatements\BankStatementImporter;
use App\Services\BankStatements\BankStatementPdfParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class BankStatementPdfParserTest extends TestCase
{
    use RefreshDatabase;

    public function test_parses_seb_ocr_table_text(): void
    {
        $path = dirname(__DIR__).DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'bank-statements'.DIRECTORY_SEPARATOR.'seb-statement-08m.ocr.txt';
        $this->assertFileExists($path);

        $parsed = (new BankStatementPdfParser)->parseText((string) file_get_contents($path), '08m.pdf');

        $this->assertSame('LT117044090110985504', $parsed['account_iban']);
        $this->assertSame('SEB', $parsed['bank_name']);
        $this->assertSame('EUR', $parsed['currency']);
        $this->assertSame('2026-08-01', $parsed['period_from']);
        $this->assertSame('2026-08-31', $parsed['period_to']);
        $this->assertGreaterThanOrEqual(20, count($parsed['transactions']));

        $first = $parsed['transactions'][0];
        $this->assertSame('2026-08-03', $first['booking_date']);
        $this->assertSame(BankStatementDirection::Credit, $first['direction']);
        $this->assertSame(14761.0, $first['amount']);
        $this->assertStringContainsStringIgnoringCase('mokes', (string) $first['counterpart_name']);

        $dsv = collect($parsed['transactions'])->first(
            fn (array $row): bool => str_contains(Str::lower((string) ($row['counterpart_name'] ?? '')), 'dsv')
                && abs((float) $row['amount'] - 177.89) < 0.001
        );
        $this->assertNotNull($dsv);
        $this->assertSame(BankStatementDirection::Debit, $dsv['direction']);

        $ownTransfer = collect($parsed['transactions'])->first(
            fn (array $row): bool => abs((float) $row['amount'] - 3255.0) < 0.001
        );
        $this->assertNotNull($ownTransfer);
        $this->assertSame(BankStatementDirection::Credit, $ownTransfer['direction']);
        $this->assertSame('LT357044090113587970', $ownTransfer['counterpart_iban']);
        $this->assertSame('Tarp savo sąskaitu (Between account)', $ownTransfer['description']);

        $vinilas = collect($parsed['transactions'])->first(
            fn (array $row): bool => abs((float) $row['amount'] - 10000.0) < 0.001
        );
        $this->assertNotNull($vinilas);
        $this->assertSame('KAR13135', $vinilas['description']);
        $this->assertStringNotContainsStringIgnoringCase('operacijos', (string) $vinilas['description']);
        $this->assertStringNotContainsStringIgnoringCase('CBVILT', (string) ($vinilas['description'] ?? ''));
    }

    public function test_parses_multi_account_pdf_sections(): void
    {
        $expense = (string) file_get_contents(
            dirname(__DIR__).DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'bank-statements'.DIRECTORY_SEPARATOR.'seb-statement-08m.ocr.txt'
        );

        $card = <<<'TXT'
UAB FUTURA TEXTILES	SĄSKAITOS IŠRAŠAS	AB SEB bankas
EUROPE
306740042	LT677044090113979371	Konstitucijos pr. 24
2026-08-01 - 2026-08-31
Pradinis likutis	EUR	100,00
2026-08-05 Komisinis atlyginimas	Bolt Operations OU	-12,50
Kortelės mokėjimas, operacijos nr. RO111
2026-08-10 Mokėj. banko viduje	UAB FUTURA TEXTILES EUROPE LT117044090110985504	50,00
Tarp savo sąskaitų (Between account), operacijos nr. RO222
Galutinis likutis	EUR	137,50
Puslapis 1(1)
TXT;

        $combined = $expense."\n\n".$card;
        $parsed = (new BankStatementPdfParser)->parseText($combined, 'multi.pdf');

        $ibans = collect($parsed['transactions'])
            ->pluck('account_iban')
            ->unique()
            ->filter()
            ->values()
            ->all();

        $this->assertContains('LT117044090110985504', $ibans);
        $this->assertContains('LT677044090113979371', $ibans);

        $bolt = collect($parsed['transactions'])->first(
            fn (array $row): bool => ($row['account_iban'] ?? null) === 'LT677044090113979371'
                && abs((float) $row['amount'] - 12.50) < 0.001
        );
        $this->assertNotNull($bolt);
        $this->assertSame(BankStatementDirection::Debit, $bolt['direction']);
    }

    public function test_rejects_truncated_ocr_page_coverage(): void
    {
        $this->expectException(\App\Exceptions\BankStatementXmlException::class);
        $this->expectExceptionMessageMatches('/pages 1–2 of 7/i');

        $text = <<<'TXT'
SĄSKAITOS IŠRAŠAS AB SEB bankas
LT117044090110985504
2026-08-01 - 2026-08-31
Pradinis likutis	EUR	100,00
2026-08-03 Mokėj. banko viduje	SEB bankas	-10,00
Test, operacijos nr. RO1
Puslapis 1(7)
2026-08-04 Komisinis atlyginimas	SEB bankas	-1,00
Mokestis, operacijos nr. RO2
Puslapis 2(7)
TXT;

        (new BankStatementPdfParser)->parseText($text, 'truncated.pdf');
    }

    public function test_import_pdf_uses_ocr_when_embedded_text_is_garbled(): void
    {
        config(['services.ocr_space.key' => 'test-key']);

        $ocrPath = dirname(__DIR__).DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'bank-statements'.DIRECTORY_SEPARATOR.'seb-statement-08m.ocr.txt';
        $pdfPath = dirname(__DIR__).DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'bank-statements'.DIRECTORY_SEPARATOR.'seb-statement-08m.pdf';
        $this->assertFileExists($pdfPath);

        Http::fake([
            'api.ocr.space/*' => Http::response([
                'ParsedResults' => [
                    ['ParsedText' => (string) file_get_contents($ocrPath)],
                ],
            ], 200),
        ]);

        $result = app(BankStatementImporter::class)->importPdf($pdfPath, '08m.pdf', comment: 'SEB PDF');

        $this->assertGreaterThanOrEqual(20, $result['imported']);
        $this->assertSame('LT117044090110985504', $result['import']->account_iban);
        $this->assertSame('SEB', $result['import']->bank_name);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'ocr.space'));
    }
}
