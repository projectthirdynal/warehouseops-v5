<?php

namespace App\Domain\Lead\Enums;

enum LeadSource: string
{
    case WAYBILL = 'WAYBILL';
    case XLSX_IMPORT = 'XLSX_IMPORT';
    case TELESALES_IMPORT = 'TELESALES_IMPORT';
    case MANUAL = 'MANUAL';
    case FACEBOOK = 'FACEBOOK';
    case SHOP = 'SHOP';

    public function label(): string
    {
        return match ($this) {
            self::WAYBILL => 'Waybill',
            self::XLSX_IMPORT => 'XLSX Import',
            self::TELESALES_IMPORT => 'Telesales (Old Sales)',
            self::MANUAL => 'Manual Entry',
            self::FACEBOOK => 'Facebook',
            self::SHOP => 'Shop',
        };
    }
}
