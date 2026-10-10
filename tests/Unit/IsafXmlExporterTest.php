<?php

namespace Tests\Unit;

use App\Models\CompanySetting;
use App\Models\Contact;
use App\Models\ExpenseType;
use App\Models\IncomeType;
use App\Models\Invoice;
use App\Models\User;
use App\Models\VatRate;
use App\Services\IsafXmlExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class IsafXmlExporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_xml_includes_sales_and_purchase_invoices_and_skips_unclassified(): void
    {
        $company = CompanySetting::query()->create([
            'company_name' => 'UAB Test',
            'company_id' => '123456789',
            'company_vat' => 'LT123456789',
            'company_country' => 'Lithuania',
        ]);

        [$sales, $purchase, $unclassified] = $this->invoices();

        $xml = app(IsafXmlExporter::class)->buildXml(
            collect([$sales, $purchase, $unclassified]),
            [
                'from' => '2026-07-01',
                'until' => '2026-07-31',
                'data_type' => IsafXmlExporter::DATA_FULL,
                'part_number' => '1',
            ],
            $company,
        );

        $this->assertStringStartsWith('<?xml', $xml);

        $document = new \DOMDocument;
        $document->loadXML($xml);
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('i', IsafXmlExporter::NS);

        $this->assertSame('iSAF1.2', $xpath->evaluate('string(/i:iSAFFile/i:Header/i:FileDescription/i:FileVersion)'));
        $this->assertSame('F', $xpath->evaluate('string(/i:iSAFFile/i:Header/i:FileDescription/i:DataType)'));
        $this->assertSame('123456789', $xpath->evaluate('string(/i:iSAFFile/i:Header/i:FileDescription/i:RegistrationNumber)'));
        $this->assertSame('2026-07-01', $xpath->evaluate('string(//i:SelectionStartDate)'));
        $this->assertSame('2026-07-31', $xpath->evaluate('string(//i:SelectionEndDate)'));

        $this->assertSame('ISAF-S-1', $xpath->evaluate('string(//i:SalesInvoices/i:Invoice/i:InvoiceNo)'));
        $this->assertSame('LT100000001', $xpath->evaluate('string(//i:SalesInvoices/i:Invoice/i:CustomerInfo/i:VATRegistrationNumber)'));
        $this->assertSame('PVM1', $xpath->evaluate('string(//i:SalesInvoices/i:Invoice/i:DocumentTotals/i:DocumentTotal/i:TaxCode)'));
        $this->assertSame('100.00', $xpath->evaluate('string(//i:SalesInvoices/i:Invoice/i:DocumentTotals/i:DocumentTotal/i:TaxableValue)'));
        $this->assertSame('21.00', $xpath->evaluate('string(//i:SalesInvoices/i:Invoice/i:DocumentTotals/i:DocumentTotal/i:Amount)'));

        $this->assertSame('ISAF-P-1', $xpath->evaluate('string(//i:PurchaseInvoices/i:Invoice/i:InvoiceNo)'));
        $this->assertSame('50.00', $xpath->evaluate('string(//i:PurchaseInvoices/i:Invoice/i:DocumentTotals/i:DocumentTotal/i:TaxableValue)'));
        $this->assertSame('10.50', $xpath->evaluate('string(//i:PurchaseInvoices/i:Invoice/i:DocumentTotals/i:DocumentTotal/i:Amount)'));

        $this->assertStringNotContainsString('ISAF-U-1', $xml);

        $this->assertSame(
            'isaf-f-20260701-20260731.xml',
            app(IsafXmlExporter::class)->fileName([
                'from' => '2026-07-01',
                'until' => '2026-07-31',
                'data_type' => 'F',
            ]),
        );
    }

    public function test_sales_only_file_omits_purchases(): void
    {
        $company = CompanySetting::query()->create([
            'company_name' => 'UAB Test',
            'company_id' => '123456789',
        ]);
        [$sales, $purchase] = $this->invoices();

        $xml = app(IsafXmlExporter::class)->buildXml(
            collect([$sales, $purchase]),
            [
                'from' => '2026-07-01',
                'until' => '2026-07-31',
                'data_type' => IsafXmlExporter::DATA_SALES,
            ],
            $company,
        );

        $this->assertStringContainsString('<DataType>S</DataType>', $xml);
        $this->assertStringContainsString('ISAF-S-1', $xml);
        $this->assertStringNotContainsString('ISAF-P-1', $xml);
        $this->assertStringNotContainsString('<PurchaseInvoices>', $xml);
    }

    public function test_missing_company_id_is_rejected(): void
    {
        $company = CompanySetting::query()->create(['company_name' => 'UAB Test']);
        [$sales] = $this->invoices();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Company id is missing');

        app(IsafXmlExporter::class)->buildXml(
            collect([$sales]),
            ['from' => '2026-07-01', 'until' => '2026-07-31'],
            $company,
        );
    }

    public function test_nd_vat_when_contact_has_no_vat_number(): void
    {
        $company = CompanySetting::query()->create([
            'company_name' => 'UAB Test',
            'company_id' => '123456789',
        ]);
        [$sales] = $this->invoices();
        $sales->contact->update([
            'company_vat' => null,
            'company_country' => 'Poland',
            'company_id' => 'PL987',
        ]);

        $xml = app(IsafXmlExporter::class)->buildXml(
            collect([$sales->fresh('contact')]),
            ['from' => '2026-07-01', 'until' => '2026-07-31', 'data_type' => IsafXmlExporter::DATA_SALES],
            $company,
        );

        $this->assertStringContainsString('<VATRegistrationNumber>ND</VATRegistrationNumber>', $xml);
        $this->assertStringContainsString('<Country>PL</Country>', $xml);
    }

    /**
     * @return list<Invoice>
     */
    private function invoices(): array
    {
        $user = User::factory()->create();
        $buyer = Contact::query()->create([
            'company_name' => 'Buyer UAB',
            'company_id' => '100000001',
            'company_vat' => 'LT100000001',
            'company_country' => 'Lithuania',
            'company_address' => 'Vilnius',
        ]);
        $seller = Contact::query()->create([
            'company_name' => 'Seller UAB',
            'company_id' => '200000002',
            'company_vat' => 'LT200000002',
            'company_country' => 'Lithuania',
            'company_address' => 'Kaunas',
        ]);
        $rate = VatRate::query()->create([
            'classificator' => 'PVM1',
            'rate' => 21,
        ]);
        $income = IncomeType::query()->create(['name' => 'Sales']);
        $expense = ExpenseType::query()->create(['name' => 'Purchases']);

        $sales = Invoice::query()->create([
            'contact_id' => $buyer->getKey(),
            'invoice_number' => 'ISAF-S-1',
            'invoice_date' => '2026-07-10',
            'upload_date' => '2026-07-10',
            'uploaded_by' => $user->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 100,
            'vat' => 21,
            'sum_inc_vat' => 121,
            'vat_rate_id' => $rate->getKey(),
            'income_type_id' => $income->getKey(),
        ]);

        $purchase = Invoice::query()->create([
            'contact_id' => $seller->getKey(),
            'invoice_number' => 'ISAF-P-1',
            'invoice_date' => '2026-07-12',
            'upload_date' => '2026-07-12',
            'uploaded_by' => $user->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 50,
            'vat' => 10.50,
            'sum_inc_vat' => 60.50,
            'vat_rate_id' => $rate->getKey(),
            'expense_type_id' => $expense->getKey(),
        ]);

        $unclassified = Invoice::query()->create([
            'contact_id' => $seller->getKey(),
            'invoice_number' => 'ISAF-U-1',
            'invoice_date' => '2026-07-14',
            'upload_date' => '2026-07-14',
            'uploaded_by' => $user->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 10,
            'vat' => 2.10,
            'sum_inc_vat' => 12.10,
            'vat_rate_id' => $rate->getKey(),
        ]);

        return [
            $sales->fresh(['contact', 'vatRate']),
            $purchase->fresh(['contact', 'vatRate']),
            $unclassified->fresh(['contact', 'vatRate']),
        ];
    }
}
