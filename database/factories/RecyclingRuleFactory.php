<?php

namespace Database\Factories;

use Modules\Leads\Enums\LeadOutcome;
use App\Models\RecyclingRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecyclingRule>
 */
class RecyclingRuleFactory extends Factory
{
    protected $model = RecyclingRule::class;

    public function definition(): array
    {
        return [
            'outcome' => LeadOutcome::NO_ANSWER->value,
            'cooldown_hours' => 24,
            'max_cycles' => 5,
            'next_action' => 'RECYCLE',
            'is_active' => true,
        ];
    }

    public function exhaust(): static
    {
        return $this->state(fn (array $attributes) => [
            'next_action' => 'EXHAUST',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
