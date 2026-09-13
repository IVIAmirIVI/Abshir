<?php

namespace Database\Factories;

use App\Models\Area;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Area>
 */
class AreaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'name' => fake()->city(),
            'code' => fake()->unique()->bothify('AREA-####'),
            'is_active' => true,
        ];
    }
}
