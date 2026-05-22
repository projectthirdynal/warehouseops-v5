<?php

declare(strict_types=1);

namespace App\Domain\Lead\Enums;

enum LeadStatus: string
{
    case NEW = 'NEW';
    case CALLING = 'CALLING';
    case NO_ANSWER = 'NO_ANSWER';
    case REJECT = 'REJECT';
    case CALLBACK = 'CALLBACK';
    case SALE = 'SALE';
    case REORDER = 'REORDER';
    case DELIVERED = 'DELIVERED';
    case RETURNED = 'RETURNED';
    case CANCELLED = 'CANCELLED';
    case ARCHIVED = 'ARCHIVED';

    public function label(): string
    {
        return match ($this) {
            self::NEW => 'New',
            self::CALLING => 'Calling',
            self::NO_ANSWER => 'No Answer',
            self::REJECT => 'Rejected',
            self::CALLBACK => 'Callback',
            self::SALE => 'Sale',
            self::REORDER => 'Reorder',
            self::DELIVERED => 'Delivered',
            self::RETURNED => 'Returned',
            self::CANCELLED => 'Cancelled',
            self::ARCHIVED => 'Archived',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NEW => 'info',
            self::CALLING => 'warning',
            self::NO_ANSWER => 'secondary',
            self::REJECT => 'danger',
            self::CALLBACK => 'warning',
            self::SALE => 'success',
            self::REORDER => 'success',
            self::DELIVERED => 'success',
            self::RETURNED => 'danger',
            self::CANCELLED => 'secondary',
            self::ARCHIVED => 'secondary',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::DELIVERED,
            self::RETURNED,
            self::CANCELLED,
            self::ARCHIVED,
        ]);
    }

    public function canTransitionTo(self $newStatus): bool
    {
        return match ($this) {
            self::NEW => in_array($newStatus, [self::CALLING, self::NO_ANSWER, self::REJECT, self::CALLBACK, self::ARCHIVED]),
            self::CALLING => in_array($newStatus, [self::NO_ANSWER, self::REJECT, self::CALLBACK, self::SALE]),
            self::NO_ANSWER => in_array($newStatus, [self::CALLING, self::CALLBACK, self::REJECT, self::ARCHIVED]),
            self::REJECT => in_array($newStatus, [self::ARCHIVED]),
            self::CALLBACK => in_array($newStatus, [self::CALLING, self::NO_ANSWER, self::REJECT, self::SALE]),
            self::SALE => in_array($newStatus, [self::DELIVERED, self::RETURNED, self::CANCELLED]),
            self::REORDER => in_array($newStatus, [self::SALE, self::CANCELLED]),
            self::DELIVERED, self::RETURNED, self::CANCELLED, self::ARCHIVED => false,
        };
    }
}
