<?php

namespace Database\Factories;

use App\Enums\CargoStatus;
use App\Models\Cargo;
use App\Models\CargoItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cargo>
 */
class CargoFactory extends Factory
{
    protected $model = Cargo::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $shipped = fake()->dateTimeBetween('-3 months', 'now');

        return [
            'tracking' => fake()->optional(0.7)->bothify('TRK-########'),
            'date_shipped' => $shipped,
            'estimated_arrival' => fake()->dateTimeBetween($shipped, '+2 months'),
            'status' => fake()->randomElement(CargoStatus::cases()),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Cargo $cargo): void {
            CargoItem::factory()
                ->count(fake()->numberBetween(1, 3))
                ->create(['cargo_id' => $cargo->id]);
        });
    }
}
