<?php

declare(strict_types=1);

namespace App\Domain\Lead\Enums;

enum DistributionStrategy: string
{
    case ROUND_ROBIN = 'round_robin';
    case WEIGHTED = 'weighted';
    case SKILL_MATCH = 'skill_match';
    case TERRITORY = 'territory';
    case HYBRID = 'hybrid';

    public function label(): string
    {
        return match ($this) {
            self::ROUND_ROBIN => 'Round Robin',
            self::WEIGHTED => 'Weighted',
            self::SKILL_MATCH => 'Skill Match',
            self::TERRITORY => 'Territory',
            self::HYBRID => 'Hybrid',
        };
    }
}
