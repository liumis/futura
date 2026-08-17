<?php

namespace Database\Factories;

use App\Models\CustomerLevel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerLevel>
 */
class CustomerLevelFactory extends Factory
{
    protected $model = CustomerLevel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Retail', 'Wholesale', 'VIP', 'Partner']).' '.fake()->numerify('##'),
        ];
    }
}
