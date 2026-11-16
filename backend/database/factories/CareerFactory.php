<?php

namespace Database\Factories;

use App\Models\Career;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Career>
 */
class CareerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('????')),
            'name' => 'Ingeniería '.fake()->word(),
            'group' => fake()->randomElement(['A', 'B']),
            'status' => 'active',
        ];
    }
}
