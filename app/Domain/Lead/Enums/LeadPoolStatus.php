<?php

declare(strict_types=1);

namespace App\Domain\Lead\Enums;

enum LeadPoolStatus: string
{
    case READY = 'READY';
    case ACTIVE = 'ACTIVE';
    case PARTIALLY_DISTRIBUTED = 'PARTIALLY_DISTRIBUTED';
    case FULLY_DISTRIBUTED = 'FULLY_DISTRIBUTED';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::READY => 'Ready',
            self::ACTIVE => 'Active',
            self::PARTIALLY_DISTRIBUTED => 'Partially Distributed',
            self::FULLY_DISTRIBUTED => 'Fully Distributed',
            self::COMPLETED => 'Completed',
            self::CANCELLED => 'Cancelled',
        };
    }

    /**
     * Statuses where the pool is still consuming leads (members may be unassigned).
     */
    public function isConsuming(): bool
    {
        return in_array($this, [
            self::READY,
            self::ACTIVE,
            self::PARTIALLY_DISTRIBUTED,
        ], true);
    }
}
