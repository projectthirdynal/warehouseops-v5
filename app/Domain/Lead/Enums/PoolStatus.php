<?php

declare(strict_types=1);

namespace App\Domain\Lead\Enums;

enum PoolStatus: string
{
    case AVAILABLE = 'AVAILABLE';
    case ASSIGNED = 'ASSIGNED';
    case COOLDOWN = 'COOLDOWN';
    case EXHAUSTED = 'EXHAUSTED';

    public function label(): string
    {
        return match ($this) {
            self::AVAILABLE => 'Available',
            self::ASSIGNED => 'Assigned',
            self::COOLDOWN => 'Cooldown',
            self::EXHAUSTED => 'Exhausted',
        };
    }
}
