<?php

namespace Database\Factories;

use App\Models\Semester;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Semester>
 */
class SemesterFactory extends Factory
{
    public function definition(): array
    {
        $year = fake()->unique()->numberBetween(2020, 2099);
        $type = fake()->randomElement(['ordinario_i', 'ordinario_ii']);

        return [
            'type' => $type,
            'code' => Semester::codeFor($type, $year),
            'year' => $year,
            'cycle' => Semester::TYPES[$type]['cycle'],
            'name' => Semester::nameFor($type, $year),
            'is_active' => false,
        ];
    }

    public function active(): static
    {
        return $this->state(['is_active' => true]);
    }

    /** Inscripción abierta hoy (de ayer a mañana). */
    public function enrollmentOpen(): static
    {
        return $this->state(fn () => [
            'enrollment_start' => now()->subDay()->toDateString(),
            'enrollment_end' => now()->addDay()->toDateString(),
        ]);
    }

    /** Modalidades reales del Ordinario II 2026: único S/ 600 o fraccionado S/ 650 (500 + 150). */
    public function withCeprePaymentPlans(): static
    {
        return $this->state(['payment_plans' => [
            'unico' => ['label' => 'Pago único', 'installments' => [600]],
            'fraccionado' => ['label' => 'Pago fraccionado', 'installments' => [500, 150]],
        ]]);
    }
}
