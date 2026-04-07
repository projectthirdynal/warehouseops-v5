<?php

declare(strict_types=1);

namespace App\Domain\Order\Enums;

enum OrderStatus: string
{
    case PENDING      = 'PENDING';
    case CONFIRMED    = 'CONFIRMED';
    case QA_PENDING   = 'QA_PENDING';
    case QA_APPROVED  = 'QA_APPROVED';
    case QA_REJECTED  = 'QA_REJECTED';
    case PROCESSING   = 'PROCESSING';
    case DISPATCHED   = 'DISPATCHED';
    case DELIVERED    = 'DELIVERED';
    case RETURNED     = 'RETURNED';
    case CANCELLED    = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING     => 'Pending',
            self::CONFIRMED   => 'Confirmed',
            self::QA_PENDING  => 'QA Pending',
            self::QA_APPROVED => 'QA Approved',
            self::QA_REJECTED => 'QA Rejected',
            self::PROCESSING  => 'Processing',
            self::DISPATCHED  => 'Dispatched',
            self::DELIVERED   => 'Delivered',
            self::RETURNED    => 'Returned',
            self::CANCELLED   => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDING     => 'gray',
            self::CONFIRMED   => 'blue',
            self::QA_PENDING  => 'yellow',
            self::QA_APPROVED => 'green',
            self::QA_REJECTED => 'red',
            self::PROCESSING  => 'blue',
            self::DISPATCHED  => 'indigo',
            self::DELIVERED   => 'green',
            self::RETURNED    => 'red',
            self::CANCELLED   => 'gray',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::DELIVERED, self::RETURNED, self::CANCELLED, self::QA_REJECTED]);
    }
}
