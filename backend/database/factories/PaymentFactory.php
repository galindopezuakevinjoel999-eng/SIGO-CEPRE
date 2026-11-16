<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_id' => User::factory()->student()->withCompletedProfile(),
            'amount' => fake()->randomFloat(2, 50, 500),
            'currency' => 'PEN',
            'status' => 'pending',
        ];
    }

    public function completed(): static
    {
        return $this->state(['status' => 'completed']);
    }
}
