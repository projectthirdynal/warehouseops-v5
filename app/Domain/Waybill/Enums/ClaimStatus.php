<?php

declare(strict_types=1);

namespace App\Domain\Waybill\Enums;

enum ClaimStatus: string
{
    case DRAFT = 'DRAFT';
    case FILED = 'FILED';
    case UNDER_REVIEW = 'UNDER_REVIEW';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case SETTLED = 'SETTLED';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT       => 'Draft',
            self::FILED       => 'Filed',
            self::UNDER_REVIEW => 'Under Review',
            self::APPROVED    => 'Approved',
            self::REJECTED    => 'Rejected',
            self::SETTLED     => 'Settled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DRAFT       => 'secondary',
            self::FILED       => 'info',
            self::UNDER_REVIEW => 'warning',
            self::APPROVED    => 'success',
            self::REJECTED    => 'danger',
            self::SETTLED     => 'success',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::APPROVED, self::REJECTED, self::SETTLED]);
    }

    public function isResolved(): bool
    {
        return in_array($this, [self::APPROVED, self::SETTLED]);
    }
}
