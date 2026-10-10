<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\StockManualUpdate;
use App\Models\User;
use App\Services\StockManualUpdateLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StockManualUpdateLoggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_write_off_decreases_stock_and_logs_change(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $product = Product::factory()->create(['current_amount' => 10]);

        $update = StockManualUpdateLogger::writeOff($product, 3, 'Damaged rolls');

        $this->assertSame(7, (int) $product->fresh()->current_amount);
        $this->assertSame(10, $update->old_amount);
        $this->assertSame(7, $update->new_amount);
        $this->assertSame($user->id, $update->user_id);
        $this->assertSame('Damaged rolls', $update->comment);
        $this->assertSame(1, StockManualUpdate::query()->count());
    }

    public function test_write_off_rejects_amount_above_stock(): void
    {
        $this->actingAs(User::factory()->create());

        $product = Product::factory()->create(['current_amount' => 2]);

        $this->expectException(ValidationException::class);

        StockManualUpdateLogger::writeOff($product, 5);

        $this->assertSame(2, (int) $product->fresh()->current_amount);
        $this->assertSame(0, StockManualUpdate::query()->count());
    }

    public function test_write_off_rejects_zero_or_negative(): void
    {
        $this->actingAs(User::factory()->create());

        $product = Product::factory()->create(['current_amount' => 5]);

        $this->expectException(ValidationException::class);

        StockManualUpdateLogger::writeOff($product, 0);
    }
}
