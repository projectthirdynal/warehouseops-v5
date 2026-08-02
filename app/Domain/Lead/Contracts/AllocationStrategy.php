<?php

declare(strict_types=1);

namespace App\Domain\Lead\Contracts;

use App\Domain\Lead\Models\Lead;
use App\Models\AgentProfile;
use Illuminate\Support\Collection;

interface AllocationStrategy
{
    /**
     * Score and rank eligible agents for a lead.
     *
     * @param  Collection<int, AgentProfile>  $agents
     * @param  array<string, mixed>  $formula  Weights and config
     * @return Collection<int, array{agent_id: int, score: float}>
     */
    public function score(Lead $lead, Collection $agents, array $formula): Collection;

    /**
     * Human-readable name for this strategy.
     */
    public function name(): string;
}
