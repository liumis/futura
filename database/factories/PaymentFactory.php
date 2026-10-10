<?php

namespace Database\Factories;

use App\Enums\PaymentFrequency;
use App\Enums\PaymentSchedule;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->sentence(3),
            'amount' => fake()->randomFloat(2, 10, 5000),
            'schedule' => PaymentSchedule::OneTime,
            'due_date' => now()->addDays(14)->toDateString(),
            'paid' => false,
        ];
    }

    public function periodical(): static
    {
        return $this->state(fn (): array => [
            'schedule' => PaymentSchedule::Periodical,
            'frequency' => PaymentFrequency::Monthly,
            'due_date' => null,
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => null,
            'paid' => false,
        ]);
    }
}
