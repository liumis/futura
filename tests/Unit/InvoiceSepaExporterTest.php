<?php

namespace Tests\Unit;

use App\Models\CompanySetting;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceSepaExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

class InvoiceSepaExporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_xml_uses_outstanding_amount_and_invoice_number_as_remittance(): void
    {
        $iban = 'LT121000011101001000';
        CompanySetting::query()->create([
            'company_name' => 'UAB Test',
            'company_iban' => $iban,
            'company_bic' => 'AGBLLT2X',
        ]);

        $contact = Contact::query()->create([
            'company_name' => 'Supplier UAB',
            'company_id' => '123',
            'company_address' => 'Vilnius',
            'bank_account' => $iban,
        ]);
        $admin = User::factory()->create();
        $invoice = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'SF-2026-15',
            'invoice_date' => '2026-08-01',
            'upload_date' => '2026-08-01',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 100,
            'vat' => 21,
            'sum_inc_vat' => 121,
            'paid_amount' => 21,
            'paid' => false,
        ]);

        $xml = app(InvoiceSepaExporter::class)->buildXml(
            collect([$invoice]),
            null,
            Carbon::parse('2026-08-28'),
        );

        $this->assertStringContainsString('xmlns="urn:iso:std:iso:20022:tech:xsd:pain.001.001.03"', $xml);
        $this->assertStringContainsString('<Nm>Supplier UAB</Nm>', $xml);
        $this->assertStringContainsString('<IBAN>'.$iban.'</IBAN>', $xml);
        $this->assertStringContainsString('Ccy="EUR">100.00</InstdAmt>', $xml);
        $this->assertStringContainsString('<Ustrd>SF-2026-15</Ustrd>', $xml);
        $this->assertStringContainsString('<ReqdExctnDt>2026-08-28</ReqdExctnDt>', $xml);
    }

    public function test_xml_accepts_partial_amount_override(): void
    {
        $iban = 'LT121000011101001000';
        CompanySetting::query()->create([
            'company_name' => 'UAB Test',
            'company_iban' => $iban,
            'company_bic' => 'AGBLLT2X',
        ]);

        $contact = Contact::query()->create([
            'company_name' => 'Supplier UAB',
            'company_id' => '123',
            'company_address' => 'Vilnius',
            'bank_account' => $iban,
        ]);
        $admin = User::factory()->create();
        $invoice = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'SF-PARTIAL',
            'invoice_date' => '2026-08-01',
            'upload_date' => '2026-08-01',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 100,
            'vat' => 21,
            'sum_inc_vat' => 121,
            'paid_amount' => 0,
            'paid' => false,
        ]);

        $xml = app(InvoiceSepaExporter::class)->buildXml(
            collect([$invoice]),
            null,
            Carbon::parse('2026-08-28'),
            [(int) $invoice->getKey() => 40.50],
        );

        $this->assertStringContainsString('Ccy="EUR">40.50</InstdAmt>', $xml);
        $this->assertStringContainsString('<Ustrd>SF-PARTIAL</Ustrd>', $xml);
    }

    public function test_xml_rejects_amount_above_outstanding(): void
    {
        $iban = 'LT121000011101001000';
        CompanySetting::query()->create([
            'company_name' => 'UAB Test',
            'company_iban' => $iban,
            'company_bic' => 'AGBLLT2X',
        ]);

        $contact = Contact::query()->create([
            'company_name' => 'Supplier UAB',
            'company_id' => '123',
            'company_address' => 'Vilnius',
            'bank_account' => $iban,
        ]);
        $admin = User::factory()->create();
        $invoice = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'SF-OVER',
            'invoice_date' => '2026-08-01',
            'upload_date' => '2026-08-01',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 100,
            'vat' => 21,
            'sum_inc_vat' => 121,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exceeds outstanding');

        app(InvoiceSepaExporter::class)->buildXml(
            collect([$invoice]),
            null,
            null,
            [(int) $invoice->getKey() => 200],
        );
    }

    public function test_xml_requires_contact_iban(): void
    {
        CompanySetting::query()->create([
            'company_name' => 'UAB Test',
            'company_iban' => 'LT121000011101001000',
            'company_bic' => 'AGBLLT2X',
        ]);

        $contact = Contact::query()->create([
            'company_name' => 'Supplier UAB',
            'company_id' => '123',
            'company_address' => 'Vilnius',
        ]);
        $admin = User::factory()->create();
        $invoice = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'SF-1',
            'invoice_date' => '2026-08-01',
            'upload_date' => '2026-08-01',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 100,
            'vat' => 21,
            'sum_inc_vat' => 121,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Bank account is missing');

        app(InvoiceSepaExporter::class)->buildXml(collect([$invoice]));
    }
}
