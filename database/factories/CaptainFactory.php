<?php

namespace Database\Factories;

use App\Enums\CaptainOperationalStatus;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Captain>
 */
class CaptainFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'home_branch_id' => Branch::factory(),
            'operational_status' => fake()->randomElement(CaptainOperationalStatus::cases()),
            'employee_code' => fake()->unique()->bothify('CAP-####'),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
