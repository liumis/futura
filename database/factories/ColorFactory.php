<?php

namespace Database\Factories;

use App\Models\Collection;
use App\Models\Color;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Color>
 */
class ColorFactory extends Factory
{
    protected $model = Color::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'collection_id' => Collection::factory(),
            'color_code' => strtoupper(fake()->bothify('??##')),
            'color_name' => fake()->colorName(),
        ];
    }
}
