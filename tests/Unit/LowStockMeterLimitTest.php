<?php

namespace Tests\Unit;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LowStockMeterLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_compares_size_times_units_not_unit_count(): void
    {
        $aboveLimit = Product::factory()->create([
            'name' => '30',
            'current_amount' => 20,
        ]);
        $belowLimit = Product::factory()->create([
            'name' => '30',
            'current_amount' => 1,
        ]);

        $ids = Product::query()->belowStockMeterLimit(50.0)->pluck('id');

        $this->assertTrue($ids->contains($belowLimit->id));
        $this->assertFalse($ids->contains($aboveLimit->id));
    }
}
