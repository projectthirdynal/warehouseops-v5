<?php

declare(strict_types=1);

namespace App\Domain\Lead\Enums;

enum PoolMemberStatus: string
{
    case PENDING = 'PENDING';
    case ASSIGNED = 'ASSIGNED';
    case REMOVED = 'REMOVED';
    case SKIPPED = 'SKIPPED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::ASSIGNED => 'Assigned',
            self::REMOVED => 'Removed',
            self::SKIPPED => 'Skipped',
        };
    }
}
