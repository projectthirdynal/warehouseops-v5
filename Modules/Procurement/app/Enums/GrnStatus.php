<?php

declare(strict_types=1);

namespace Modules\Procurement\Enums;

enum GrnStatus: string
{
    case DRAFT = 'DRAFT';
    case CONFIRMED = 'CONFIRMED';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::CONFIRMED => 'Confirmed',
        };
    }
}
