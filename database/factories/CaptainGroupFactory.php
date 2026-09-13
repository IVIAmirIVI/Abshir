<?php

namespace Database\Factories;

use App\Models\CaptainGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CaptainGroup>
 */
class CaptainGroupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'code' => fake()->unique()->bothify('GROUP-###'),
            'description' => fake()->optional()->sentence(),
            'is_active' => true,
        ];
    }
}
