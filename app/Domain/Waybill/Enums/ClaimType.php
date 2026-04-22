<?php

declare(strict_types=1);

namespace App\Domain\Waybill\Enums;

enum ClaimType: string
{
    case LOST = 'LOST';
    case DAMAGED = 'DAMAGED';
    case BEYOND_SLA = 'BEYOND_SLA';

    public function label(): string
    {
        return match ($this) {
            self::LOST      => 'Lost Parcel',
            self::DAMAGED   => 'Damaged Parcel',
            self::BEYOND_SLA => 'Beyond SLA',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::LOST      => 'danger',
            self::DAMAGED   => 'warning',
            self::BEYOND_SLA => 'info',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::LOST      => 'Parcel was lost in transit by J&T Express.',
            self::DAMAGED   => 'Parcel was delivered in damaged condition.',
            self::BEYOND_SLA => 'J&T tagged as returned but parcel not received by next calendar day.',
        };
    }
}
