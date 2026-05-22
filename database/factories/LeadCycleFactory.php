<?php

namespace Database\Factories;

use App\Domain\Lead\Models\Lead;
use App\Models\LeadCycle;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\LeadCycle>
 */
class LeadCycleFactory extends Factory
{
    protected $model = LeadCycle::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $leadId = Lead::factory();

        return [
            'lead_id' => $leadId,
            'cycle_number' => fn (array $attributes) => ((int) DB::table('lead_cycles')
                ->where('lead_id', $attributes['lead_id'])
                ->max('cycle_number')) + 1,
            'assigned_agent_id' => User::factory(),
            'status' => 'ACTIVE',
            'outcome' => null,
            'opened_at' => now(),
            'closed_at' => null,
            'last_call_at' => null,
            'call_count' => 0,
            'callback_at' => null,
            'callback_notes' => null,
        ];
    }

    /**
     * Indicate that the cycle is closed.
     */
    public function closed(string $outcome = 'COMPLETED'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'CLOSED',
            'outcome' => $outcome,
            'closed_at' => now(),
        ]);
    }
}
