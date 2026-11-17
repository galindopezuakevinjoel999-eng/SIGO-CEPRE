<?php

namespace Database\Factories;

use App\Models\Exam;
use App\Models\Semester;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Exam>
 *
 * Puntos de prueba (no son los oficiales del CEPRE): +4 correcta, -1 incorrecta, 0 en blanco.
 */
class ExamFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Simulacro '.fake()->numberBetween(1, 9),
            'type' => 'simulacro',
            'date' => now()->toDateString(),
            'semester_id' => Semester::factory()->active(),
            'group' => null,
            'total_questions' => 10,
            'points_correct' => 4,
            'points_wrong' => -1,
            'points_blank' => 0,
            'status' => Exam::STATUS_DRAFT,
        ];
    }

    public function published(): static
    {
        return $this->state(['status' => Exam::STATUS_PUBLISHED, 'published_at' => now()]);
    }
}
