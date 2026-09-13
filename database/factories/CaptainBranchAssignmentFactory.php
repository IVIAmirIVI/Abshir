<?php

namespace Database\Factories;

use App\Enums\CaptainAssignmentType;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\CaptainBranchAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CaptainBranchAssignment>
 */
class CaptainBranchAssignmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'captain_id' => Captain::factory(),
            'assignment_type' => CaptainAssignmentType::Temporary,
            'branch_id' => Branch::factory(),
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'reason' => fake()->sentence(),
            'created_by_user_id' => User::factory(),
        ];
    }
}
