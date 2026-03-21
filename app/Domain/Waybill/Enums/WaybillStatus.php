<?php

declare(strict_types=1);

namespace App\Domain\Waybill\Enums;

enum WaybillStatus: string
{
    case PENDING = 'PENDING';
    case DISPATCHED = 'DISPATCHED';
    case PICKED_UP = 'PICKED_UP';
    case IN_TRANSIT = 'IN_TRANSIT';
    case ARRIVED_HUB = 'ARRIVED_HUB';
    case OUT_FOR_DELIVERY = 'OUT_FOR_DELIVERY';
    case DELIVERY_FAILED = 'DELIVERY_FAILED';
    case DELIVERED = 'DELIVERED';
    case RETURNING = 'RETURNING';
    case RETURNED = 'RETURNED';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::DISPATCHED => 'Dispatched',
            self::PICKED_UP => 'Picked Up',
            self::IN_TRANSIT => 'In Transit',
            self::ARRIVED_HUB => 'Arrived at Hub',
            self::OUT_FOR_DELIVERY => 'Out for Delivery',
            self::DELIVERY_FAILED => 'Delivery Failed',
            self::DELIVERED => 'Delivered',
            self::RETURNING => 'Returning',
            self::RETURNED => 'Returned',
            self::CANCELLED => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'warning',
            self::DISPATCHED => 'info',
            self::PICKED_UP => 'info',
            self::IN_TRANSIT => 'info',
            self::ARRIVED_HUB => 'info',
            self::OUT_FOR_DELIVERY => 'info',
            self::DELIVERY_FAILED => 'danger',
            self::DELIVERED => 'success',
            self::RETURNING => 'warning',
            self::RETURNED => 'danger',
            self::CANCELLED => 'secondary',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::DELIVERED,
            self::RETURNED,
            self::CANCELLED,
        ]);
    }

    public function isActive(): bool
    {
        return in_array($this, [
            self::DISPATCHED,
            self::PICKED_UP,
            self::IN_TRANSIT,
            self::ARRIVED_HUB,
            self::OUT_FOR_DELIVERY,
        ]);
    }
}
