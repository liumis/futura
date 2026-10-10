<?php

namespace Tests\Feature;

use App\Enums\CargoStatus;
use App\Filament\Admin\Resources\ImportTaxPayments\Pages\ListImportTaxPayments;
use App\Models\Cargo;
use App\Models\CargoItem;
use App\Models\CustomsContact;
use App\Models\ImportTaxPayment;
use App\Models\Product;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ImportTaxPaymentCustomsContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_shows_customs_company_and_defaults_the_only_contact(): void
    {
        $this->actingAsAdmin();

        $contact = CustomsContact::query()->create([
            'company_name' => 'Muitinė UAB',
        ]);
        $product = Product::factory()->create(['name' => '20']);
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
            'amount' => 1,
            'self_cost' => 10,
        ]);
        ImportTaxPayment::syncFromCargo($cargo->fresh(['cargoItems.product.productType', 'importTax']));

        $payment = ImportTaxPayment::query()->where('cargo_id', $cargo->getKey())->firstOrFail();

        Livewire::test(ListImportTaxPayments::class)
            ->assertOk()
            ->assertSee('Customs company')
            ->assertSee('Service cost')
            ->assertSee('Total cost')
            ->assertSee('Make payment')
            ->assertCanSeeTableRecords([$payment]);

        $this->assertSame($contact->getKey(), $payment->customs_contact_id);
    }

    private function actingAsAdmin(): User
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create(['email' => 'admin-import-tax-customs@example.com']);
        $admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        return $admin;
    }
}
