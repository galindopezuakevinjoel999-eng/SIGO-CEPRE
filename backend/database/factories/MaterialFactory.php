<?php

namespace Database\Factories;

use App\Models\Material;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Material>
 */
class MaterialFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uploaded_by' => User::factory()->teacher(),
            'title' => 'Separata '.fake()->words(2, true),
            'file_path' => 'materials/'.fake()->uuid().'.pdf',
            'file_name' => 'separata.pdf',
            'file_size' => 1024,
            'type' => 'pdf',
            'status' => 'published',
        ];
    }

    public function draft(): static
    {
        return $this->state(['status' => 'draft']);
    }
}
