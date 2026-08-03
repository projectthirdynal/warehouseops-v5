<?php

declare(strict_types=1);

namespace App\Domain\Lead\Strategies;

use App\Domain\Lead\Contracts\AllocationStrategy;
use App\Domain\Lead\Enums\DistributionStrategy;
use App\Services\PredictiveAssignmentService;

class StrategyResolver
{
    public static function resolve(DistributionStrategy $strategy): AllocationStrategy
    {
        return match ($strategy) {
            DistributionStrategy::ROUND_ROBIN => new RoundRobinStrategy,
            DistributionStrategy::WEIGHTED => new WeightedStrategy,
            DistributionStrategy::SKILL_MATCH => new SkillMatchStrategy,
            DistributionStrategy::TERRITORY => new TerritoryStrategy,
            DistributionStrategy::HYBRID => new HybridStrategy,
            DistributionStrategy::PREDICTIVE => new PredictiveStrategy(app(PredictiveAssignmentService::class)),
        };
    }
}
