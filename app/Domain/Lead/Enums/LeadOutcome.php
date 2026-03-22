<?php

declare(strict_types=1);

namespace App\Domain\Lead\Enums;

enum LeadOutcome: string
{
    case NO_ANSWER = 'NO_ANSWER';
    case CALLBACK = 'CALLBACK';
    case INTERESTED = 'INTERESTED';
    case ORDERED = 'ORDERED';
    case NOT_INTERESTED = 'NOT_INTERESTED';
    case WRONG_NUMBER = 'WRONG_NUMBER';

    public function label(): string
    {
        return match ($this) {
            self::NO_ANSWER => 'No Answer',
            self::CALLBACK => 'Callback',
            self::INTERESTED => 'Interested',
            self::ORDERED => 'Ordered/Sold',
            self::NOT_INTERESTED => 'Not Interested',
            self::WRONG_NUMBER => 'Wrong Number',
        };
    }
}
