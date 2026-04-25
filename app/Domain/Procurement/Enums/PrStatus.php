<?php

declare(strict_types=1);

namespace App\Domain\Procurement\Enums;

enum PrStatus: string
{
    case DRAFT     = 'DRAFT';
    case SUBMITTED = 'SUBMITTED';
    case APPROVED  = 'APPROVED';
    case CONVERTED = 'CONVERTED';
    case REJECTED  = 'REJECTED';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT     => 'Draft',
            self::SUBMITTED => 'Submitted',
            self::APPROVED  => 'Approved',
            self::CONVERTED => 'Converted to PO',
            self::REJECTED  => 'Rejected',
            self::CANCELLED => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DRAFT     => 'gray',
            self::SUBMITTED => 'blue',
            self::APPROVED  => 'green',
            self::CONVERTED => 'emerald',
            self::REJECTED  => 'red',
            self::CANCELLED => 'orange',
        };
    }
}
