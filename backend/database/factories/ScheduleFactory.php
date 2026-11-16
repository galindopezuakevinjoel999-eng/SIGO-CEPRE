<?php

namespace Database\Factories;

use App\Models\Career;
use App\Models\Classroom;
use App\Models\Schedule;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Schedule>
 */
class ScheduleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'teacher_id' => User::factory()->teacher(),
            'career_id' => Career::factory(),
            'classroom_id' => Classroom::factory(),
            'semester_id' => Semester::factory(),
            'days' => ['lunes', 'miercoles'],
            'start_time' => '08:00:00',
            'end_time' => '09:30:00',
        ];
    }
}
