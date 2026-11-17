<?php

namespace Database\Factories;

use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExamResult>
 */
class ExamResultFactory extends Factory
{
    public function definition(): array
    {
        return [
            'exam_id' => Exam::factory(),
            'student_id' => User::factory()->student(),
            'correct' => 6,
            'wrong' => 2,
            'blank' => 2,
            'score' => 22,
            'group' => 'A',
            'rank' => 1,
            'rank_in_group' => 1,
        ];
    }
}
