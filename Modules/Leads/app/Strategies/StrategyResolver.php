<?php

declare(strict_types=1);

namespace Modules\Leads\Strategies;

use App\Services\PredictiveAssignmentService;
use Modules\Leads\Contracts\AllocationStrategy;
use Modules\Leads\Enums\DistributionStrategy;

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
