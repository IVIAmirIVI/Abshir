<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class BranchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->city(),
            'code' => fake()->unique()->bothify('BR-####??'),
            'is_active' => true,
        ];
    }
}
