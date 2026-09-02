<?php

declare(strict_types=1);

namespace Modules\Shop\Services;

use Modules\Shop\Models\AddressMapping;
use Modules\Shop\Models\ShippingRate;

class ShippingRateService
{
    /**
     * Calculate shipping fee for a given address and courier.
     *
     * @param  array{province?: ?string, city_municipality?: ?string, barangay?: ?string, address?: ?string}  $addressInput
     * @param  array{quantity?: int, weight?: float}  $options
     * @return array{fee: float, zone: ?string, rate: ?ShippingRate, has_rate: bool}
     */
    public function calculate(array $addressInput, string $courierCode = 'MANUAL', array $options = []): array
    {
        $zone = $this->resolveZone($addressInput);

        if ($zone === null) {
            return ['fee' => 0, 'zone' => null, 'rate' => null, 'has_rate' => false];
        }

        $rate = ShippingRate::query()
            ->where('courier_code', $courierCode)
            ->where('courier_zone', $zone)
            ->where('is_active', true)
            ->first();

        if (! $rate) {
            $rate = ShippingRate::query()
                ->where('courier_code', 'MANUAL')
                ->where('courier_zone', $zone)
                ->where('is_active', true)
                ->first();
        }

        if (! $rate) {
            return ['fee' => 0, 'zone' => $zone, 'rate' => null, 'has_rate' => false];
        }

        $weight = (float) ($options['weight'] ?? 0);
        $baseFee = (float) $rate->base_fee;
        $perKgFee = (float) $rate->per_kg_fee;
        $threshold = (float) $rate->weight_threshold_kg;

        $fee = $baseFee;
        if ($weight > $threshold && $perKgFee > 0) {
            $fee += ceil(($weight - $threshold) / 1.0) * $perKgFee;
        }

        return ['fee' => $fee, 'zone' => $zone, 'rate' => $rate, 'has_rate' => true];
    }

    /**
     * Resolve the courier zone from address input via AddressMapping.
     *
     * @param  array{province?: ?string, city_municipality?: ?string, barangay?: ?string, address?: ?string}  $input
     */
    private function resolveZone(array $input): ?string
    {
        $province = mb_strtolower(trim((string) ($input['province'] ?? '')));
        $city = mb_strtolower(trim((string) ($input['city_municipality'] ?? '')));
        $barangay = mb_strtolower(trim((string) ($input['barangay'] ?? '')));

        $query = AddressMapping::query();

        if ($province !== '') {
            $query->whereRaw('LOWER(province) = ?', [$province]);
        }

        if ($city !== '') {
            $query->whereRaw('LOWER(city_municipality) = ?', [$city]);
        }

        if ($barangay !== '') {
            $query->whereRaw('LOWER(COALESCE(barangay, \'\')) = ?', [$barangay]);
        }

        $mapping = ($province !== '' || $city !== '' || $barangay !== '') ? $query->first() : null;

        return $mapping?->courier_zone;
    }
}
