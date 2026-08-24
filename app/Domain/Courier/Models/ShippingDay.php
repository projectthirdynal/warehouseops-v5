<?php

declare(strict_types=1);

namespace App\Domain\Courier\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingDay extends Model
{
    protected $fillable = [
        'province',
        'city',
        'barangay',
        'shipping_days',
    ];

    protected $casts = [
        'shipping_days' => 'integer',
    ];

    /**
     * Normalize a province/city name for matching.
     * The shipping data uses hyphens (e.g. "AGUSAN-DEL-NORTE") while
     * lead data may use spaces (e.g. "Agusan del Norte").
     */
    public static function normalize(string $name): string
    {
        return mb_strtoupper(str_replace(' ', '-', trim($name)));
    }

    /**
     * Find shipping days for a location with fallback logic:
     * 1. Exact barangay + city + province match
     * 2. City + province match (any barangay)
     * 3. Province-only match
     */
    public static function findForLocation(string $province, ?string $city = null, ?string $barangay = null): ?self
    {
        $normalizedProvince = self::normalize($province);
        $normalizedCity = $city ? self::normalize($city) : null;
        $normalizedBarangay = $barangay ? mb_strtoupper(trim($barangay)) : null;

        // Try exact barangay match first
        if ($normalizedBarangay && $normalizedCity) {
            $exact = self::where('province', $normalizedProvince)
                ->where('city', $normalizedCity)
                ->where('barangay', $normalizedBarangay)
                ->first();

            if ($exact) {
                return $exact;
            }
        }

        // Fall back to city + province match (any barangay)
        if ($normalizedCity) {
            $cityMatch = self::where('province', $normalizedProvince)
                ->where('city', $normalizedCity)
                ->first();

            if ($cityMatch) {
                return $cityMatch;
            }
        }

        // Fall back to province-only match
        return self::where('province', $normalizedProvince)
            ->first();
    }
}
