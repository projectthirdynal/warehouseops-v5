<?php

declare(strict_types=1);

namespace App\Domain\Lead\Enums;

enum SalesStatus: string
{
    case NEW = 'NEW';
    case CONTACTED = 'CONTACTED';
    case AGENT_CONFIRMED = 'AGENT_CONFIRMED';
    case QA_PENDING = 'QA_PENDING';
    case QA_APPROVED = 'QA_APPROVED';
    case QA_REJECTED = 'QA_REJECTED';
    case OPS_APPROVED = 'OPS_APPROVED';
    case CANCELLED = 'CANCELLED';
    case WAYBILL_CREATED = 'WAYBILL_CREATED';

    public function label(): string
    {
        return match ($this) {
            self::NEW => 'New',
            self::CONTACTED => 'Contacted',
            self::AGENT_CONFIRMED => 'Agent Confirmed',
            self::QA_PENDING => 'QA Pending',
            self::QA_APPROVED => 'QA Approved',
            self::QA_REJECTED => 'QA Rejected',
            self::OPS_APPROVED => 'Ops Approved',
            self::CANCELLED => 'Cancelled',
            self::WAYBILL_CREATED => 'Waybill Created',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NEW => 'secondary',
            self::CONTACTED => 'info',
            self::AGENT_CONFIRMED => 'info',
            self::QA_PENDING => 'warning',
            self::QA_APPROVED => 'success',
            self::QA_REJECTED => 'danger',
            self::OPS_APPROVED => 'success',
            self::CANCELLED => 'secondary',
            self::WAYBILL_CREATED => 'success',
        };
    }

    public function canCreateWaybill(): bool
    {
        return in_array($this, [
            self::QA_APPROVED,
            self::OPS_APPROVED,
        ]);
    }

    public function isPendingReview(): bool
    {
        return $this === self::QA_PENDING;
    }
}
