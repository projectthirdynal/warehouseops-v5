<?php

declare(strict_types=1);

namespace App\Domain\Lead\Enums;

enum PoolRequestStatus: string
{
    case DRAFT = 'DRAFT';
    case PENDING_APPROVAL = 'PENDING_APPROVAL';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case CANCELLED = 'CANCELLED';
    case PARTIALLY_DISTRIBUTED = 'PARTIALLY_DISTRIBUTED';
    case DISTRIBUTED = 'DISTRIBUTED';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::PENDING_APPROVAL => 'Pending Approval',
            self::APPROVED => 'Approved',
            self::REJECTED => 'Rejected',
            self::CANCELLED => 'Cancelled',
            self::PARTIALLY_DISTRIBUTED => 'Partially Distributed',
            self::DISTRIBUTED => 'Distributed',
        };
    }

    /**
     * Statuses where the request is still active and actionable.
     */
    public function isActive(): bool
    {
        return in_array($this, [
            self::DRAFT,
            self::PENDING_APPROVAL,
            self::APPROVED,
            self::PARTIALLY_DISTRIBUTED,
        ], true);
    }
}
