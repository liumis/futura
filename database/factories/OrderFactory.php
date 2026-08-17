<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'status' => fake()->randomElement(OrderStatus::cases()),
            'amount' => fake()->randomFloat(2, 10, 5000),
            'shipping_cost' => fake()->randomFloat(2, 0, 50),
            'order_date' => now(),
            'user_id' => User::factory(),
        ];
    }
}
