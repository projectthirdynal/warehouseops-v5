<?php

declare(strict_types=1);

namespace App\Domain\Lead\Strategies;

use App\Domain\Lead\Contracts\AllocationStrategy;
use App\Domain\Lead\Models\Lead;
use App\Models\AgentProfile;
use App\Services\PredictiveAssignmentService;
use Illuminate\Support\Collection;

class PredictiveStrategy implements AllocationStrategy
{
    public function __construct(
        private readonly PredictiveAssignmentService $predictiveService,
    ) {}

    public function score(Lead $lead, Collection $agents, array $formula): Collection
    {
        $result = $this->predictiveService->predict($lead, $agents);

        // Build a ranked collection from the prediction result
        // The service returns the best agent; we map all agents with their scores
        $bestId = $result['agent_id'];

        return $agents->map(function (AgentProfile $agent) use ($bestId, $result) {
            return [
                'agent_id' => $agent->user_id,
                'score' => $agent->user_id === $bestId ? $result['score'] : 0.0,
            ];
        })->sortByDesc('score')->values();
    }

    public function name(): string
    {
        return 'Predictive (ML)';
    }
}
