<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerPhone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerPhone>
 */
class CustomerPhoneFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'phone' => '+963 9'.fake()->numerify('## ### ###'),
            'normalized_phone' => fake()->unique()->numerify('9639########'),
            'label' => fake()->randomElement(['Mobile', 'Home', 'Work']),
            'is_primary' => false,
        ];
    }
}
