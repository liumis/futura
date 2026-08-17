<?php

namespace Database\Factories;

use App\Models\Color;
use App\Models\Product;
use App\Models\ProductType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_type_id' => fn () => ProductType::artificialLeatherId()
                ?? ProductType::query()->create([
                    'name' => 'Artificial leather',
                    'key' => ProductType::KEY_ARTIFICIAL_LEATHER,
                    'requires_color' => true,
                ])->id,
            'name' => '20',
            'color_id' => Color::factory(),
            'product_code' => Str::upper(Str::random(8)),
            'current_amount' => fake()->numberBetween(0, 10_000),
            'default_cost' => 0,
        ];
    }

    public function catalog(): static
    {
        return $this->state(fn (): array => [
            'product_type_id' => fn () => ProductType::catalogId()
                ?? ProductType::query()->create([
                    'name' => 'Catalog',
                    'key' => ProductType::KEY_CATALOG,
                    'requires_color' => false,
                ])->id,
            'color_id' => null,
            'name' => fake()->words(3, true),
        ]);
    }
}
