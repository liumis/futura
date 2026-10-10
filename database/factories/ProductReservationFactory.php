<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductReservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductReservation>
 */
class ProductReservationFactory extends Factory
{
    protected $model = ProductReservation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'quantity' => fake()->numberBetween(1, 20),
        ];
    }
}
