<?php

namespace Database\Factories;

use App\Models\Area;
use App\Models\Customer;
use App\Models\CustomerAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerAddress>
 */
class CustomerAddressFactory extends Factory
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
            'area_id' => Area::factory(),
            'label' => fake()->randomElement(['Home', 'Work']),
            'recipient_name' => fake()->name(),
            'address_line_1' => fake()->streetAddress(),
            'landmark' => fake()->optional()->sentence(3),
            'is_primary' => false,
            'is_active' => true,
        ];
    }
}
