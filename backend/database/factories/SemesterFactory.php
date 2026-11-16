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
        $year = fake()->unique()->numberBetween(2020, 2035);
        $cycle = fake()->randomElement([1, 2]);

        return [
            'code' => substr((string) $year, -2).$cycle, // 2026-II → 262
            'year' => $year,
            'cycle' => $cycle,
            'name' => $year.'-'.($cycle === 1 ? 'I' : 'II'),
            'is_active' => false,
        ];
    }

    public function active(): static
    {
        return $this->state(['is_active' => true]);
    }
}
