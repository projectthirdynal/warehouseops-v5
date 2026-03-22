<?php

namespace Database\Factories;

use App\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Lead>
 */
class LeadFactory extends Factory
{
    protected $model = Lead::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => fake()->phoneNumber(),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => fake()->state(),
            'barangay' => fake()->word(),
            'postal_code' => fake()->postcode(),
            'status' => 'AVAILABLE',
            'sales_status' => 'NEW',
            'source' => fake()->randomElement(['WEB', 'PHONE', 'REFERRAL', 'WALK_IN']),
            'product_name' => fake()->words(3, true),
            'product_brand' => fake()->company(),
            'product_sku' => fake()->bothify('???-####'),
            'amount' => fake()->randomFloat(2, 100, 10000),
            'total_cycles' => 0,
            'quality_score' => fake()->numberBetween(0, 100),
        ];
    }
}
