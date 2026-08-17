<?php

namespace Database\Factories;

use App\Models\Collection;
use App\Models\CustomerLevel;
use App\Models\CustomerLevelPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerLevelPrice>
 */
class CustomerLevelPriceFactory extends Factory
{
    protected $model = CustomerLevelPrice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_level_id' => CustomerLevel::factory(),
            'collection_id' => Collection::factory(),
            'price' => fake()->randomFloat(2, 10, 200),
        ];
    }
}
