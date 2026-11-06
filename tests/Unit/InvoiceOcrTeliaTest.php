<?php

namespace Tests\Unit;

use App\Services\InvoiceOcrService;
use Tests\TestCase;

class InvoiceOcrTeliaTest extends TestCase
{
    public function test_parses_lithuanian_telia_totals(): void
    {
        $text = <<<'TXT'
Telia Lietuva, AB
PVM mokėtojo kodas LT121141818
Sąskaitos Nr. 1234567890
Sąskaitos data: 2026-09-30
Suma be PVM 41,32
PVM suma 8,68
Mokėtina suma 50,00 EUR
TXT;

        $result = (new InvoiceOcrService)->parseRawText($text);

        $this->assertStringContainsString('Telia', (string) ($result['provider_company_name'] ?? ''));
        $this->assertSame('2026-09-30', $result['invoice_date'] ?? null);
        $this->assertSame(41.32, $result['sum_without_vat'] ?? null);
        $this->assertSame(8.68, $result['vat'] ?? null);
        $this->assertSame(50.0, $result['sum_inc_vat'] ?? null);
    }
}
