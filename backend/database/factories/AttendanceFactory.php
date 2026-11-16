<?php

namespace Database\Factories;

use App\Models\Attendance;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attendance>
 */
class AttendanceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_id' => User::factory()->student(),
            'schedule_id' => Schedule::factory(),
            // Fechas distintas: la tabla no admite dos registros del mismo
            // estudiante en el mismo horario y día.
            'date' => fake()->unique()->dateTimeBetween('-1 year')->format('Y-m-d'),
            'status' => 'present',
        ];
    }

    public function absent(): static
    {
        return $this->state(['status' => 'absent']);
    }
}
