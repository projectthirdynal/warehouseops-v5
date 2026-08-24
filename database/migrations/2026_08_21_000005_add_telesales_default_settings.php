<?php

use App\Models\SystemSetting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        SystemSetting::set(
            'telesales_max_waybill_age_days',
            60,
            'telesales',
            'int',
            'Maximum waybill age (in days) for a lead to be eligible for telesales pooling.'
        );

        SystemSetting::set(
            'telesales_invalid_phone_patterns',
            json_encode(['/^0+$/', '/^9{10,}$/', '/^123/', '/^\s*$/']),
            'telesales',
            'json',
            'Regex patterns for phone numbers considered invalid for telesales.'
        );
    }

    public function down(): void
    {
        SystemSetting::where('key', 'telesales_max_waybill_age_days')->delete();
        SystemSetting::where('key', 'telesales_invalid_phone_patterns')->delete();
    }
};
