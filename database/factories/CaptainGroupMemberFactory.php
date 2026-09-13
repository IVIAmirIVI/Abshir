<?php

namespace Database\Factories;

use App\Models\Captain;
use App\Models\CaptainGroup;
use App\Models\CaptainGroupMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CaptainGroupMember>
 */
class CaptainGroupMemberFactory extends Factory
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
            'captain_group_id' => CaptainGroup::factory(),
            'joined_at' => now()->subDay(),
            'left_at' => null,
            'created_by_user_id' => User::factory(),
        ];
    }
}
