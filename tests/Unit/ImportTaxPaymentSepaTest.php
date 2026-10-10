<?php

namespace Tests\Unit;

use App\Enums\CargoStatus;
use App\Enums\ImportTaxPaymentStatus;
use App\Models\Cargo;
use App\Models\CargoItem;
use App\Models\CompanySetting;
use App\Models\CustomsContact;
use App\Models\ImportTaxPayment;
use App\Models\Product;
use App\Services\ImportTaxPaymentSepaExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

class ImportTaxPaymentSepaTest extends TestCase
{
    use RefreshDatabase;

    public function test_total_cost_adds_import_tax_and_service_cost(): void
    {
        $payment = $this->makePayment([
            'tax_amount' => 40.5,
            'service_cost' => 9.5,
        ]);

        $this->assertSame(50.0, $payment->totalCost());
        $this->assertSame(ImportTaxPaymentStatus::Unpaid, $payment->status);
    }

    public function test_sync_from_cargo_keeps_service_cost_and_status(): void
    {
        $cargo = $this->makeCargo();
        ImportTaxPayment::syncFromCargo($cargo);

        $payment = ImportTaxPayment::query()->where('cargo_id', $cargo->getKey())->firstOrFail();
        $payment->update([
            'service_cost' => 12.34,
            'status' => ImportTaxPaymentStatus::Payed,
        ]);

        ImportTaxPayment::syncFromCargo($cargo->fresh(['cargoItems.product.productType', 'importTax']));

        $payment = $payment->fresh();
        $this->assertSame('12.34', (string) $payment->service_cost);
        $this->assertSame(ImportTaxPaymentStatus::Payed, $payment->status);
    }

    public function test_sepa_xml_pays_customs_company_the_total_cost(): void
    {
        $iban = 'LT121000011101001000';
        CompanySetting::query()->create([
            'company_name' => 'UAB Test',
            'company_iban' => $iban,
            'company_bic' => 'AGBLLT2X',
        ]);

        $payment = $this->makePayment([
            'tax_amount' => 40,
            'service_cost' => 10,
            'bank_account' => $iban,
        ]);

        $xml = app(ImportTaxPaymentSepaExporter::class)->buildXml(
            collect([$payment]),
            null,
            Carbon::parse('2026-08-28'),
        );

        $this->assertStringContainsString('xmlns="urn:iso:std:iso:20022:tech:xsd:pain.001.001.03"', $xml);
        $this->assertStringContainsString('<Nm>Muitine UAB</Nm>', $xml);
        $this->assertStringContainsString('<IBAN>'.$iban.'</IBAN>', $xml);
        $this->assertStringContainsString('Ccy="EUR">50.00</InstdAmt>', $xml);
        $this->assertStringContainsString('Import tax and service WO '.$payment->cargo_id, $xml);
        $this->assertStringContainsString('<ReqdExctnDt>2026-08-28</ReqdExctnDt>', $xml);
    }

    public function test_sepa_requires_customs_iban(): void
    {
        CompanySetting::query()->create([
            'company_name' => 'UAB Test',
            'company_iban' => 'LT121000011101001000',
            'company_bic' => 'AGBLLT2X',
        ]);

        $payment = $this->makePayment([
            'tax_amount' => 40,
            'service_cost' => 10,
            'bank_account' => null,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Bank account is missing');

        app(ImportTaxPaymentSepaExporter::class)->buildXml(collect([$payment]));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makePayment(array $overrides = []): ImportTaxPayment
    {
        $contact = CustomsContact::query()->create([
            'company_name' => 'Muitine UAB',
            'bank_account' => $overrides['bank_account'] ?? null,
        ]);
        unset($overrides['bank_account']);

        $cargo = $this->makeCargo();

        return ImportTaxPayment::query()->create(array_merge([
            'cargo_id' => $cargo->getKey(),
            'customs_contact_id' => $contact->getKey(),
            'tax_rate' => 0,
            'amount' => 1,
            'line_value' => 100,
            'tax_amount' => 0,
            'service_cost' => 0,
            'status' => ImportTaxPaymentStatus::Unpaid,
            'received_date' => '2026-08-01',
        ], $overrides));
    }

    private function makeCargo(): Cargo
    {
        $product = Product::factory()->create([
            'name' => '20',
            'current_amount' => 0,
        ]);

        $cargo = Cargo::query()->create([
            'status' => CargoStatus::Received,
            'date_shipped' => '2026-07-01',
            'estimated_arrival' => '2026-08-01',
            'shipping_cost' => 0,
            'additional_cost' => 0,
        ]);

        CargoItem::query()->create([
            'cargo_id' => $cargo->getKey(),
            'product_id' => $product->getKey(),
            'amount' => 2,
            'self_cost' => 10,
        ]);

        return $cargo;
    }
}
