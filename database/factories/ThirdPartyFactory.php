<?php

namespace Database\Factories;

use App\Models\ThirdParty;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ThirdParty>
 */
class ThirdPartyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ref' => fake()->unique()->regexify('[A-Z]{3}-[0-9]{4}'),
            'name' => fake()->company(),
            'type' => 'customer',
            'status' => 'active',
            'email' => fake()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'street' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => fake()->state(),
            'country' => 'Philippines',
            'postal_code' => fake()->postcode(),
        ];
    }

    public function supplier(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'supplier',
        ]);
    }
}
