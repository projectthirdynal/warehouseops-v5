<?php

namespace Database\Factories;

use App\Models\AgentProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AgentProfile>
 */
class AgentProfileFactory extends Factory
{
    protected $model = AgentProfile::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'max_active_cycles' => $this->faker->numberBetween(5, 20),
            'product_skills' => [],
            'regions' => [],
            'priority_weight' => $this->faker->randomFloat(2, 0.5, 2.0),
            'is_available' => true,
            'performance_score' => $this->faker->numberBetween(0, 100),
            'last_assignment_at' => null,
        ];
    }

    /**
     * Indicate that the agent is unavailable.
     */
    public function unavailable(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_available' => false,
        ]);
    }
}
