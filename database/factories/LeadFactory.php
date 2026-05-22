<?php

namespace Database\Factories;

use App\Domain\Lead\Enums\PoolStatus;
use App\Domain\Lead\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Domain\Lead\Models\Lead>
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
            'status' => 'NEW',
            'sales_status' => 'NEW',
            'source' => fake()->randomElement(['WEB', 'PHONE', 'REFERRAL', 'WALK_IN']),
            'product_name' => fake()->words(3, true),
            'product_brand' => fake()->company(),
            'amount' => fake()->randomFloat(2, 100, 10000),
            'total_cycles' => 0,
            'max_cycles' => 3,
            'is_exhausted' => false,
            'quality_score' => fake()->numberBetween(0, 100),
            'pool_status' => PoolStatus::AVAILABLE,
        ];
    }
}
