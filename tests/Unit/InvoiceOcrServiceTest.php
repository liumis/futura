<?php

namespace Tests\Unit;

use App\Services\InvoiceOcrService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InvoiceOcrServiceTest extends TestCase
{
    public function test_parses_lithuanian_invoice_text(): void
    {
        $text = <<<'TXT'
UAB Pavyzdys
Įmonės kodas 301234567
PVM mokėtojo kodas LT301234567
Sąskaita faktūra Nr. SF-2026-10
Data: 2026-09-16
Suma be PVM 100,00
PVM suma 21,00
Iš viso 121,00
info@pavyzdys.lt
TXT;

        $result = (new InvoiceOcrService)->parseRawText($text);

        $this->assertSame('Pavyzdys, UAB', $result['provider_company_name']);
        $this->assertSame('301234567', $result['provider_company_id']);
        $this->assertSame('LT301234567', $result['provider_company_vat']);
        $this->assertSame('SF-2026-10', $result['invoice_number']);
        $this->assertSame('2026-09-16', $result['invoice_date']);
        $this->assertSame(100.0, $result['sum_without_vat']);
        $this->assertSame(21.0, $result['vat']);
        $this->assertSame(121.0, $result['sum_inc_vat']);
        $this->assertSame('info@pavyzdys.lt', $result['provider_company_email']);
    }

    public function test_skips_buyer_label_when_guessing_provider(): void
    {
        $text = <<<'TXT'
Pirkėjas Customer
UAB Tikras Tiekejas
Sąskaita faktūra Nr. SF-9
Data: 2026-09-16
Suma be PVM 10,00
Iš viso 12,10
TXT;

        $result = (new InvoiceOcrService)->parseRawText($text);

        $this->assertSame('Tikras Tiekejas, UAB', $result['provider_company_name']);
    }

    public function test_prefers_seller_block_over_buyer(): void
    {
        $text = <<<'TXT'
Pirkėjas / Customer
FUTURA TEXTILES, UAB
Pardavėjas / Seller
UAB Tikras Tiekejas
Sąskaita faktūra Nr. SF-88
Data: 2026-09-16
Suma be PVM 10,00
Mokėtina suma 12,10
TXT;

        $result = (new InvoiceOcrService)->parseRawText($text);

        $this->assertSame('Tikras Tiekejas, UAB', $result['provider_company_name']);
        $this->assertSame(12.10, $result['sum_inc_vat']);
    }

    public function test_derives_net_amount_from_total_and_vat(): void
    {
        $text = <<<'TXT'
UAB Pavyzdys
PVM mokėtojo kodas LT301234567
Mokėtina suma 121,00
PVM suma 21,00
TXT;

        $result = (new InvoiceOcrService)->parseRawText($text);

        $this->assertSame(121.0, $result['sum_inc_vat']);
        $this->assertSame(21.0, $result['vat']);
        $this->assertSame(100.0, $result['sum_without_vat']);
    }

    public function test_parses_dsv_road_recurring_invoice(): void
    {
        config(['services.ocr_space.key' => null]);
        Http::fake();

        $path = dirname(__DIR__).DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'invoices'.DIRECTORY_SEPARATOR.'dsv-road-1025402.pdf';
        $this->assertFileExists($path);

        $result = (new InvoiceOcrService)->extract($path);

        $this->assertSame('DSV Road, UAB', $result['provider_company_name'] ?? null);
        $this->assertSame('111552645', $result['provider_company_id'] ?? null);
        $this->assertSame('LT115526417', $result['provider_company_vat'] ?? null);
        $this->assertSame('1025402', $result['invoice_number'] ?? null);
        $this->assertSame('2026-08-31', $result['invoice_date'] ?? null);
        $this->assertSame(1439.84, $result['sum_without_vat'] ?? null);
        $this->assertSame(302.37, $result['vat'] ?? null);
        $this->assertSame(1742.21, $result['sum_inc_vat'] ?? null);
        Http::assertNothingSent();
    }

    public function test_parses_anthropic_stripe_receipt(): void
    {
        config(['services.ocr_space.key' => null]);
        Http::fake();

        $path = dirname(__DIR__).DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'invoices'.DIRECTORY_SEPARATOR.'anthropic-receipt-2772-4106-7857.pdf';
        $this->assertFileExists($path);

        $result = (new InvoiceOcrService)->extract($path);

        $this->assertSame('Anthropic, PBC', $result['provider_company_name'] ?? null);
        $this->assertSame('support@anthropic.com', $result['provider_company_email'] ?? null);
        $this->assertSame('IE4276970QH', $result['provider_company_vat'] ?? null);
        $this->assertSame('KUBTJ8F9-0015', $result['invoice_number'] ?? null);
        $this->assertSame('2026-08-01', $result['invoice_date'] ?? null);
        $this->assertSame(10.18, $result['sum_without_vat'] ?? null);
        $this->assertSame(0.0, $result['vat'] ?? null);
        $this->assertSame(10.18, $result['sum_inc_vat'] ?? null);
        Http::assertNothingSent();
    }

    public function test_parses_anthropic_receipt_text_without_picking_buyer(): void
    {
        $text = <<<'TXT'
Receipt
Invoice numberKUBTJ8F9-0015
Receipt number2772-4106-7857
Date paid August 1, 2026
VAT RegistrationEU VAT: IE4276970QH
Anthropic, PBC(@anthropic)
support@anthropic.com
Bill to
UAB Futura Textiles Europe
arman.deividas@gmail.com
LT VATLT100017651210
€10.18 paid on August 1, 2026
Subtotal	€10.18
Total	€10.18
Amount paid	€10.18
[1] Tax to be paid on reverse charge basis
TXT;

        $result = (new InvoiceOcrService)->parseRawText($text);

        $this->assertSame('Anthropic, PBC', $result['provider_company_name']);
        $this->assertSame('IE4276970QH', $result['provider_company_vat']);
        $this->assertSame('KUBTJ8F9-0015', $result['invoice_number']);
        $this->assertSame('2026-08-01', $result['invoice_date']);
        $this->assertSame(10.18, $result['sum_inc_vat']);
        $this->assertSame(0.0, $result['vat']);
        $this->assertSame('support@anthropic.com', $result['provider_company_email']);
        $this->assertSame('EUR', $result['currency']);
    }

    public function test_detects_usd_currency(): void
    {
        $text = <<<'TXT'
Anthropic, PBC
Invoice number KUBTJ8F9-0015
Date paid August 1, 2026
Total USD 10.18
TXT;

        $result = (new InvoiceOcrService)->parseRawText($text);

        $this->assertSame('USD', $result['currency']);
        $this->assertSame(10.18, $result['sum_inc_vat']);
    }

    public function test_parses_lithuanian_pvm_saskaita_faktura(): void
    {
        config(['services.ocr_space.key' => null]);
        Http::fake();

        $path = dirname(__DIR__).DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'invoices'.DIRECTORY_SEPARATOR.'pvm-sf-sp-522324.pdf';
        $this->assertFileExists($path);

        $result = (new InvoiceOcrService)->extract($path);

        $this->assertSame('Technologijų era, UAB', $result['provider_company_name'] ?? null);
        $this->assertSame('300151980', $result['provider_company_id'] ?? null);
        $this->assertSame('LT100001986013', $result['provider_company_vat'] ?? null);
        $this->assertSame('SP-522324', $result['invoice_number'] ?? null);
        $this->assertSame('2026-07-31', $result['invoice_date'] ?? null);
        $this->assertSame(306.52, $result['sum_without_vat'] ?? null);
        $this->assertSame(0.0, $result['vat'] ?? null);
        $this->assertSame(306.52, $result['sum_inc_vat'] ?? null);
        $this->assertSame('info@siuskpigiau.lt', $result['provider_company_email'] ?? null);
        Http::assertNothingSent();
    }

    public function test_parses_german_rechnung_hotel_invoice(): void
    {
        config(['services.ocr_space.key' => null]);
        Http::fake();

        $path = dirname(__DIR__).DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'invoices'.DIRECTORY_SEPARATOR.'german-rechnung-ar-20113.pdf';
        $this->assertFileExists($path);

        $result = (new InvoiceOcrService)->extract($path);

        $this->assertSame('20113', $result['invoice_number'] ?? null);
        $this->assertSame('2026-04-24', $result['invoice_date'] ?? null);
        $this->assertSame(1010.28, $result['sum_without_vat'] ?? null);
        $this->assertSame(70.72, $result['vat'] ?? null);
        $this->assertSame(1081.0, $result['sum_inc_vat'] ?? null);
        $this->assertNotSame('UAB Futura Textiles Europe', $result['provider_company_name'] ?? null);
        Http::assertNothingSent();
    }

    public function test_parses_german_rechnung_text_with_hotel_email(): void
    {
        $text = <<<'TXT'
UAB Futura Textiles Europe
Ringuvos g. 30-2
78415 SIAULIAI
LITAUEN
Rechnung:	20113
24.04.2026 1 306740042 / LT100017651210
MwSt.Grundlage MwSt.% MwSt.
1.010,28 7: 7 % MwSt.	70,72 1.081,00
Bruttobetrag:1.081,00 EUR
Endsumme: 1.081,00 EUR
info@waldhotel-stuttgart.de
TXT;

        $result = (new InvoiceOcrService)->parseRawText($text);

        $this->assertSame('20113', $result['invoice_number']);
        $this->assertSame('2026-04-24', $result['invoice_date']);
        $this->assertSame(1081.0, $result['sum_inc_vat']);
        $this->assertSame(70.72, $result['vat']);
        $this->assertSame(1010.28, $result['sum_without_vat']);
        $this->assertSame('Waldhotel Stuttgart', $result['provider_company_name']);
        $this->assertSame('info@waldhotel-stuttgart.de', $result['provider_company_email']);
    }

    public function test_parses_finvalda_efvs_invoice(): void
    {
        config(['services.ocr_space.key' => null]);
        Http::fake();

        $path = dirname(__DIR__).DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'invoices'.DIRECTORY_SEPARATOR.'finvalda-efvs-848.pdf';
        $this->assertFileExists($path);

        $result = (new InvoiceOcrService)->extract($path);

        $this->assertSame('Finansų valdymo sistemos, UAB', $result['provider_company_name'] ?? null);
        $this->assertSame('EFVS2604-848', $result['invoice_number'] ?? null);
        $this->assertSame('2026-04-10', $result['invoice_date'] ?? null);
        $this->assertSame(115.0, $result['sum_without_vat'] ?? null);
        $this->assertSame(24.15, $result['vat'] ?? null);
        $this->assertSame(139.15, $result['sum_inc_vat'] ?? null);
        $this->assertSame('LT236190219', $result['provider_company_vat'] ?? null);
        $this->assertSame('123619023', $result['provider_company_id'] ?? null);
        Http::assertNothingSent();
    }

    public function test_parses_divcom_uk_invoice(): void
    {
        config(['services.ocr_space.key' => null]);
        Http::fake();

        $path = dirname(__DIR__).DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'invoices'.DIRECTORY_SEPARATOR.'divcom-inv201888144.pdf';
        $this->assertFileExists($path);

        $result = (new InvoiceOcrService)->extract($path);

        $this->assertSame('Diversified Business Communications UK Limited', $result['provider_company_name'] ?? null);
        $this->assertSame('accounts@divcom.co.uk', $result['provider_company_email'] ?? null);
        $this->assertSame('GB991282494', $result['provider_company_vat'] ?? null);
        $this->assertSame('INV201888144', $result['invoice_number'] ?? null);
        $this->assertSame('2026-03-30', $result['invoice_date'] ?? null);
        $this->assertSame(150.0, $result['sum_without_vat'] ?? null);
        $this->assertSame(0.0, $result['vat'] ?? null);
        $this->assertSame(150.0, $result['sum_inc_vat'] ?? null);
        $this->assertSame('GBP', $result['currency'] ?? null);
        Http::assertNothingSent();
    }

    public function test_skips_seb_bank_statement_even_after_ocr(): void
    {
        config(['services.ocr_space.key' => 'test-key']);

        Http::fake([
            'api.ocr.space/*' => Http::response([
                'ParsedResults' => [[
                    'ParsedText' => "SEB\nSĄSKAITOS IŠRAŠAS\nLT117044090110985504\nPradinis likutis\nOperacijos tipas\nDSV ROAD UAB\n",
                ]],
            ], 200),
        ]);

        $path = dirname(__DIR__).DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'invoices'.DIRECTORY_SEPARATOR.'seb-statement-08m.pdf';
        $this->assertFileExists($path);

        $result = (new InvoiceOcrService)->extract($path);

        $this->assertSame([], $result);
    }

    public function test_invalid_bytes_are_stripped_so_livewire_json_can_encode(): void
    {
        $text = "UAB Pavyzdys\0\x80\nSąskaita faktūra Nr. SF-9\nData: 2026-09-16\nIš viso 12,10\n";

        $result = (new InvoiceOcrService)->parseRawText($text);

        $this->assertSame('Pavyzdys, UAB', $result['provider_company_name'] ?? null);
        json_encode($result, JSON_THROW_ON_ERROR);
        $this->assertTrue(mb_check_encoding((string) ($result['provider_company_name'] ?? ''), 'UTF-8'));
    }

    public function test_repairs_nfm_chamber_invoice(): void
    {
        config(['services.ocr_space.key' => null]);
        config(['services.invoice_ai.key' => null]);
        Http::fake();

        $path = dirname(__DIR__).DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'invoices'.DIRECTORY_SEPARATOR.'nfm-253.pdf';
        $this->assertFileExists($path);

        $result = (new InvoiceOcrService)->extract($path);

        $this->assertSame('2026-08-27', $result['invoice_date'] ?? null);
        $this->assertSame('NFM-0000253', $result['invoice_number'] ?? null);
        $this->assertSame('LT100675917', $result['provider_company_vat'] ?? null);
        $this->assertSame('110067596', $result['provider_company_id'] ?? null);
        $this->assertSame(50.0, $result['sum_without_vat'] ?? $result['sum_inc_vat'] ?? null);
        $this->assertSame(0.0, $result['vat'] ?? null);
        Http::assertNothingSent();
    }

    public function test_resolve_uploaded_path_accepts_associative_array(): void
    {
        Storage::fake('public');
        $relative = 'invoices/ocr-sample.txt';
        Storage::disk('public')->put($relative, 'Sąskaita faktūra');

        $resolved = InvoiceOcrService::resolveUploadedPath($relative);
        $this->assertNotNull($resolved);

        $fromArray = InvoiceOcrService::resolveUploadedPath(['uuid-1' => $relative]);
        $this->assertSame($resolved, $fromArray);

        $this->assertNotNull($resolved);
        $this->assertFileExists($resolved);
    }

    public function test_extract_reads_digital_pdf_without_ocr_api(): void
    {
        config(['services.ocr_space.key' => null]);
        Http::fake();

        $binary = Pdf::loadHTML(
            '<html><body><p>UAB Pavyzdys</p><p>Saskaita faktura</p><p>Suma be PVM 10.00</p><p>Is viso 12.10</p></body></html>'
        )->output();

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'invoice-ocr-'.uniqid('', true).'.pdf';
        file_put_contents($path, $binary);

        try {
            $result = (new InvoiceOcrService)->extract($path);
        } finally {
            @unlink($path);
        }

        $this->assertNotSame([], $result);
        $this->assertArrayHasKey('provider_company_name', $result);
    }

    public function test_parses_fte_own_sales_invoice_pdf(): void
    {
        config(['services.ocr_space.key' => null]);
        Http::fake();

        $path = dirname(__DIR__).DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'invoices'.DIRECTORY_SEPARATOR.'fte-0123-sales.pdf';
        $this->assertFileExists($path);

        $result = (new InvoiceOcrService)->extract($path);

        $this->assertSame('A & T Style ltd', $result['provider_company_name'] ?? null);
        $this->assertSame('IE9689962L', $result['provider_company_vat'] ?? null);
        $this->assertArrayNotHasKey('provider_company_id', $result);
        $this->assertSame('FTE-0123', $result['invoice_number'] ?? null);
        $this->assertSame('2026-07-01', $result['invoice_date'] ?? null);
        $this->assertSame(2370.0, $result['sum_without_vat'] ?? null);
        $this->assertSame(0.0, $result['vat'] ?? null);
        $this->assertSame(2370.0, $result['sum_inc_vat'] ?? null);
        $this->assertSame('EUR', $result['currency'] ?? null);
        $this->assertTrue(($result['is_own_sales_invoice'] ?? false) === true);
        Http::assertNothingSent();
    }

    public function test_parses_fte_own_sales_invoice_text(): void
    {
        $text = <<<'TXT'
PVM SĄSKAITA FAKTŪRA  SERIJA 	FTE  NR.: 0123
PARDAVĖJAS:	PIRKĖJAS:	2026-07-01
UAB  FUTURA TEXTILES EUROPE
Ringuvos g. 30-2, LT-78415 , Šiauliai , Lietuva
A & T Style ltd
27-35 Hebron industrial estate Unit 2 , Kilkenny ,
R95FK02, Ireland
Bankas: 70440 - SEB bankas
A / S: LT357044090113587970, SWIFT: CBVILT2XXXX
Įm. kodas: 306740042
PVM mokėtojo kodas: LT100017651210
Bankas:
A / S:
Įm. kodas:
PVM mokėtojo kodas: IE9689962L
VISO BE PVM:
SUMA APMOKĖJIMUI:
 0.00
 2370.00
PVM 0 %:
 2370.00
VISO BE PVM:
SUMA APMOKĖJIMUI: 2370.00
 0.00
PVM SUMA:
Apmokėti iki: 2026-07-31
 2370.00
TXT;

        $result = (new InvoiceOcrService)->parseRawText($text);

        $this->assertSame('A & T Style ltd', $result['provider_company_name']);
        $this->assertSame('IE9689962L', $result['provider_company_vat']);
        $this->assertArrayNotHasKey('provider_company_id', $result);
        $this->assertSame('FTE-0123', $result['invoice_number']);
        $this->assertSame('2026-07-01', $result['invoice_date']);
        $this->assertSame(2370.0, $result['sum_without_vat']);
        $this->assertSame(0.0, $result['vat']);
        $this->assertSame(2370.0, $result['sum_inc_vat']);
        $this->assertTrue($result['is_own_sales_invoice'] === true);
    }

    public function test_extract_uses_ocr_space_for_images(): void
    {
        config(['services.ocr_space.key' => 'test-key']);

        Http::fake([
            'api.ocr.space/*' => Http::response([
                'ParsedResults' => [[
                    'ParsedText' => "Telia Lietuva\nInvoice date: 16/09/2026\nSubtotal 50.00\nVAT 10.50\nTotal 60.50\n",
                ]],
            ], 200),
        ]);

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'invoice-ocr-'.uniqid('', true).'.jpg';
        file_put_contents($path, 'fake-image');

        try {
            $result = (new InvoiceOcrService)->extract($path);
        } finally {
            @unlink($path);
        }

        $this->assertSame('Telia Lietuva', $result['provider_company_name'] ?? null);
        $this->assertSame('2026-09-16', $result['invoice_date'] ?? null);
        $this->assertSame(50.0, $result['sum_without_vat'] ?? null);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.ocr.space/parse/image');
    }
}
