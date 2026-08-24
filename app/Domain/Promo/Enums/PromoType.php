<?php

declare(strict_types=1);

namespace App\Domain\Promo\Enums;

enum PromoType: string
{
    case FREEBIE = 'FREEBIE';
    case BUNDLE = 'BUNDLE';
    case DISCOUNT = 'DISCOUNT';

    public function label(): string
    {
        return match ($this) {
            self::FREEBIE => 'Free Item',
            self::BUNDLE => 'Bundle (B1T1/B1T2)',
            self::DISCOUNT => 'Discount',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::FREEBIE => 'Customer gets a free item with their order',
            self::BUNDLE => 'Buy X quantity, get Y free (e.g. Buy 1 Take 1)',
            self::DISCOUNT => 'Percentage discount on the order total',
        };
    }
}
