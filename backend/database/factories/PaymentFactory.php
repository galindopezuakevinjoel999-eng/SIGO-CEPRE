<?php

namespace Database\Factories;

use App\Models\Enrollment;
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
            // La matrícula pertenece al mismo estudiante que el pago.
            'enrollment_id' => fn (array $attributes) => Enrollment::factory()->create([
                'student_id' => $attributes['student_id'],
            ])->id,
            'amount' => fake()->randomFloat(2, 50, 500),
            'currency' => 'PEN',
            'status' => Payment::STATUS_PENDING,
        ];
    }

    public function validated(): static
    {
        return $this->state(['status' => Payment::STATUS_VALIDATED, 'validated_at' => now()]);
    }

    public function rejected(): static
    {
        return $this->state(['status' => Payment::STATUS_REJECTED, 'rejection_reason' => 'Comprobante ilegible']);
    }
}
