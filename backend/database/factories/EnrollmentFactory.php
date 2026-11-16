<?php

namespace Database\Factories;

use App\Models\Career;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Enrollment>
 */
class EnrollmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_id' => User::factory()->student()->withCompletedProfile(),
            'career_id' => Career::factory(),
            'status' => 'pending',
            'current_step' => 2,
        ];
    }

    public function approved(): static
    {
        return $this->state(['status' => 'approved', 'approved_at' => now()]);
    }
}
