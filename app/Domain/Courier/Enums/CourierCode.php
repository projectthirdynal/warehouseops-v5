<?php

declare(strict_types=1);

namespace App\Domain\Courier\Enums;

enum CourierCode: string
{
    case FLASH = 'FLASH';
    case JNT = 'JNT';
    case MANUAL = 'MANUAL';

    public function label(): string
    {
        return match ($this) {
            self::FLASH => 'Flash Express',
            self::JNT => 'J&T Express',
            self::MANUAL => 'Manual',
        };
    }
}
