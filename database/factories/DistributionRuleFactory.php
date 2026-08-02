<?php

namespace Database\Factories;

use App\Domain\Lead\Enums\DistributionStrategy;
use App\Models\DistributionRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DistributionRule>
 */
class DistributionRuleFactory extends Factory
{
    protected $model = DistributionRule::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->words(3, true),
            'strategy' => DistributionStrategy::HYBRID,
            'priority' => $this->faker->numberBetween(0, 10),
            'filters' => null,
            'weight_formula' => null,
            'is_active' => true,
            'supervisor_id' => User::factory(),
        ];
    }
}
