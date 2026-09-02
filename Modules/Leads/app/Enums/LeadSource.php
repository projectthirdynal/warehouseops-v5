<?php

namespace Modules\Leads\Enums;

enum LeadSource: string
{
    case WAYBILL = 'WAYBILL';
    case XLSX_IMPORT = 'XLSX_IMPORT';
    case TELESALES_IMPORT = 'TELESALES_IMPORT';
    case MANUAL = 'MANUAL';
    case FACEBOOK = 'FACEBOOK';
    case SHOP = 'SHOP';
    case WEB = 'WEB';
    case PHONE = 'PHONE';
    case REFERRAL = 'REFERRAL';
    case WALK_IN = 'WALK_IN';
    case DELIVERED_WAYBILL = 'DELIVERED_WAYBILL';

    public function label(): string
    {
        return match ($this) {
            self::WAYBILL => 'Waybill',
            self::XLSX_IMPORT => 'XLSX Import',
            self::TELESALES_IMPORT => 'Telesales (Old Sales)',
            self::MANUAL => 'Manual Entry',
            self::FACEBOOK => 'Facebook',
            self::SHOP => 'Shop',
            self::WEB => 'Web',
            self::PHONE => 'Phone',
            self::REFERRAL => 'Referral',
            self::WALK_IN => 'Walk-In',
            self::DELIVERED_WAYBILL => 'Delivered Waybill',
        };
    }
}
