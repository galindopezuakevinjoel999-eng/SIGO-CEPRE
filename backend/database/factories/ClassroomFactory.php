<?php

namespace Database\Factories;

use App\Models\Classroom;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Classroom>
 */
class ClassroomFactory extends Factory
{
    public function definition(): array
    {
        $number = str_pad((string) fake()->unique()->numberBetween(1, 99), 2, '0', STR_PAD_LEFT);

        return [
            'number' => $number,
            'name' => "Aula {$number}",
            'capacity' => 40,
            'status' => 'active',
        ];
    }
}
