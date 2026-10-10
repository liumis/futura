<?php

namespace Tests\Unit;

use App\Enums\CargoStatus;
use App\Models\Cargo;
use App\Models\CargoItem;
use App\Models\CustomsContact;
use App\Models\ImportTaxPayment;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportTaxPaymentCustomsContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_customs_contact_is_selected_by_default(): void
    {
        $contact = CustomsContact::query()->create([
            'company_name' => 'Muitinė UAB',
        ]);
        $cargo = $this->makeCargo();

        ImportTaxPayment::syncFromCargo($cargo);

        $payment = ImportTaxPayment::query()->where('cargo_id', $cargo->getKey())->first();

        $this->assertNotNull($payment);
        $this->assertSame($contact->getKey(), $payment->customs_contact_id);
    }

    public function test_customs_company_stays_empty_when_there_are_multiple_contacts(): void
    {
        CustomsContact::query()->create(['company_name' => 'Muitinė UAB']);
        CustomsContact::query()->create(['company_name' => 'Other customs']);
        $cargo = $this->makeCargo();

        ImportTaxPayment::syncFromCargo($cargo);

        $payment = ImportTaxPayment::query()->where('cargo_id', $cargo->getKey())->first();

        $this->assertNotNull($payment);
        $this->assertNull($payment->customs_contact_id);
    }

    public function test_existing_customs_company_is_kept_on_resync(): void
    {
        $first = CustomsContact::query()->create(['company_name' => 'Muitinė UAB']);
        $second = CustomsContact::query()->create(['company_name' => 'Other customs']);
        $cargo = $this->makeCargo();

        ImportTaxPayment::syncFromCargo($cargo);
        $payment = ImportTaxPayment::query()->where('cargo_id', $cargo->getKey())->firstOrFail();
        $payment->update(['customs_contact_id' => $second->getKey()]);

        ImportTaxPayment::syncFromCargo($cargo->fresh(['cargoItems.product.productType', 'importTax']));

        $this->assertSame($second->getKey(), $payment->fresh()->customs_contact_id);
        $this->assertNotSame($first->getKey(), $payment->fresh()->customs_contact_id);
    }

    private function makeCargo(): Cargo
    {
        $product = Product::factory()->create([
            'name' => '20',
            'current_amount' => 0,
        ]);

        $cargo = Cargo::query()->create([
            'status' => CargoStatus::Shipped,
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

        return $cargo->fresh(['cargoItems.product.productType', 'importTax']);
    }
}
