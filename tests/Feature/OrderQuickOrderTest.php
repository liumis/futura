<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\Orders\Pages\CreateOrder;
use App\Models\Collection;
use App\Models\Color;
use App\Models\Product;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrderQuickOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_order_has_quick_order_and_cart_but_not_auto_order(): void
    {
        $this->actingAsAdmin();

        Livewire::test(CreateOrder::class)
            ->assertOk()
            ->assertActionExists('quickOrder')
            ->assertActionExists('expandOrderCart')
            ->assertActionDoesNotExist('autoOrder')
            ->assertDontSee('Auto order');
    }

    public function test_quick_order_applies_matched_amounts_to_order_form(): void
    {
        $this->actingAsAdmin();

        $collection = Collection::factory()->create(['name' => 'Agnona']);
        $color = Color::factory()->create([
            'collection_id' => $collection->id,
            'color_code' => '102',
            'color_name' => 'Arctic',
            'recommended_stock' => 500,
        ]);
        $product = Product::factory()->create([
            'color_id' => $color->id,
            'name' => '30',
            'product_code' => 'AG102ARCTIC-30',
            'alternative_code' => 'AGNONA102ARCTIC30',
            'current_amount' => 2,
        ]);

        Livewire::test(CreateOrder::class)
            ->callAction('quickOrder', data: [
                'raw_text' => 'AGNONA102ARCTIC30 — 4',
                'lines' => [
                    [
                        'product_id' => $product->id,
                        'code' => 'AGNONA102ARCTIC30',
                        'label' => 'Agnona · 102 Arctic',
                        'amount' => 4,
                        'unit' => 'rolls',
                        'matched' => true,
                        'stock' => 2,
                        'size_meters' => 30.0,
                        'detail' => '',
                    ],
                ],
                'summary' => 'Užsakymas:',
            ])
            ->assertSet('data.order_amounts.'.$product->id, 4)
            ->assertSet('orderCartOpen', true);
    }

    public function test_order_product_row_marks_low_stock_read_only_recommended(): void
    {
        $this->actingAsAdmin();

        $collection = Collection::factory()->create(['name' => 'Borga']);
        Color::factory()->create([
            'collection_id' => $collection->id,
            'color_code' => '121',
            'color_name' => 'Black',
            'recommended_stock' => 1000,
        ]);
        Product::factory()->create([
            'color_id' => Color::query()->where('color_code', '121')->value('id'),
            'name' => '30',
            'current_amount' => 1,
        ]);

        $html = Livewire::test(CreateOrder::class)->html();

        $this->assertStringContainsString('fi-cargo-order-row--low-stock', $html);
        $this->assertStringContainsString('fi-cargo-order-rec-btn is-disabled', $html);
        $this->assertStringContainsString('Recommended stock / low-stock benchmark (read-only)', $html);
        $this->assertStringNotContainsString('data-recommended-stock="1" class="fi-cargo-order-rec-btn"', $html);
    }

    public function test_order_row_low_stock_uses_color_total_not_each_size_sku(): void
    {
        $this->actingAsAdmin();

        $collection = Collection::factory()->create(['name' => 'Agnona']);
        $color = Color::factory()->create([
            'collection_id' => $collection->id,
            'color_code' => '103',
            'color_name' => 'Blanc',
            'recommended_stock' => 200,
        ]);
        Product::factory()->create([
            'color_id' => $color->id,
            'name' => '30',
            'product_code' => 'AG103-30',
            'current_amount' => 7, // 210 m — above recommended
        ]);
        $emptyPartial = Product::factory()->create([
            'color_id' => $color->id,
            'name' => '25',
            'product_code' => 'AG103-25',
            'current_amount' => 0,
        ]);

        $html = Livewire::test(CreateOrder::class)->html();

        $this->assertTrue(
            (bool) preg_match(
                '/class="([^"]*fi-cargo-order-row[^"]*)"[^>]*data-stock-meters="([^"]*)"[^>]*>[\s\S]*?data-order-amount-input="'.$emptyPartial->id.'"/',
                $html,
                $match,
            ),
            'AG103-25 catalog row not found',
        );

        $this->assertStringNotContainsString(
            'fi-cargo-order-row--low-stock',
            $match[1],
            'Empty size SKU should not be low-stock when color total meters meet recommended',
        );
        $this->assertSame('210', $match[2], 'data-stock-meters should be the color total, not the empty SKU');
    }

    public function test_amount_input_updates_cart_without_auto_order(): void
    {
        $this->actingAsAdmin();

        $product = Product::factory()->create(['current_amount' => 10]);

        Livewire::test(CreateOrder::class)
            ->call('onOrderAmountInputUpdated', $product->id, 3)
            ->assertSet('data.order_amounts.'.$product->id, 3)
            ->assertSet('orderCartOpen', true)
            ->assertDispatched('order-amount-synced');
    }

    private function actingAsAdmin(): User
    {
        Role::findOrCreate('admin');

        $admin = User::factory()->create(['email' => 'admin-order-quick@example.com']);
        $admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        return $admin;
    }
}
