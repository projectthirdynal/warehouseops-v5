<?php

declare(strict_types=1);

namespace App\Domain\Procurement\Enums;

enum PoStatus: string
{
    case DRAFT              = 'DRAFT';
    case SENT               = 'SENT';
    case PARTIALLY_RECEIVED = 'PARTIALLY_RECEIVED';
    case RECEIVED           = 'RECEIVED';
    case CANCELLED          = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT              => 'Draft',
            self::SENT               => 'Sent to Supplier',
            self::PARTIALLY_RECEIVED => 'Partially Received',
            self::RECEIVED           => 'Fully Received',
            self::CANCELLED          => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DRAFT              => 'gray',
            self::SENT               => 'blue',
            self::PARTIALLY_RECEIVED => 'yellow',
            self::RECEIVED           => 'green',
            self::CANCELLED          => 'red',
        };
    }
}
