<?php

namespace Database\Factories;

use App\Models\Cargo;
use App\Models\CargoItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CargoItem>
 */
class CargoItemFactory extends Factory
{
    protected $model = CargoItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cargo_id' => Cargo::factory(),
            'product_id' => Product::factory(),
            'amount' => fake()->numberBetween(1, 500),
        ];
    }
}
