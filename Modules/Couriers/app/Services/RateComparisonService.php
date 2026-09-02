<?php

declare(strict_types=1);

namespace Modules\Couriers\Services;

use App\Domain\Shop\Models\ShippingRate;
use App\Domain\Shop\Services\ShippingRateService;
use Modules\Couriers\Models\CourierProvider;

class RateComparisonService
{
    public function __construct(
        private ShippingRateService $shippingRateService,
    ) {}

    /**
     * Compare shipping rates across all active couriers for a given address + weight.
     *
     * @param  array{province?: ?string, city_municipality?: ?string, barangay?: ?string, address?: ?string}  $addressInput
     * @param  array{weight?: float, cod_amount?: float, item_value?: float}  $options
     * @return array{
     *     zone: ?string,
     *     rates: array<int, array{
     *         courier_code: string,
     *         courier_name: string,
     *         base_fee: float,
     *         per_kg_fee: float,
     *         cod_fee: float,
     *         total_fee: float,
     *         estimated_days: ?int,
     *         is_active: bool,
     *         has_rate: bool,
     *         zone: ?string,
     *     }>,
     *     cheapest: ?array,
     *     fastest: ?array,
     * }
     */
    public function compareRates(array $addressInput, array $options = []): array
    {
        $weight = (float) ($options['weight'] ?? 0);
        $codAmount = (float) ($options['cod_amount'] ?? 0);

        $providers = CourierProvider::where('is_active', true)
            ->orderBy('name')
            ->get();

        $rates = [];
        $zone = null;

        foreach ($providers as $provider) {
            $result = $this->shippingRateService->calculate(
                $addressInput,
                $provider->code,
                ['weight' => $weight],
            );

            if ($zone === null && $result['zone']) {
                $zone = $result['zone'];
            }

            $baseFee = $result['has_rate'] ? (float) $result['rate']->base_fee : 0;
            $perKgFee = $result['has_rate'] ? (float) $result['rate']->per_kg_fee : 0;
            $codFee = $result['has_rate'] ? (float) $result['rate']->cod_fee : 0;

            $totalFee = $result['fee'];
            if ($codAmount > 0 && $codFee > 0) {
                $totalFee += $codFee;
            }

            $estimatedDays = $this->getEstimatedDays($provider->code);

            $rates[] = [
                'courier_code' => $provider->code,
                'courier_name' => $provider->name,
                'base_fee' => $baseFee,
                'per_kg_fee' => $perKgFee,
                'cod_fee' => $codFee,
                'total_fee' => $totalFee,
                'estimated_days' => $estimatedDays,
                'is_active' => $provider->is_active,
                'has_rate' => $result['has_rate'],
                'zone' => $result['zone'],
            ];
        }

        $cheapest = $this->findCheapest($rates);
        $fastest = $this->findFastest($rates);

        return [
            'zone' => $zone,
            'rates' => $rates,
            'cheapest' => $cheapest,
            'fastest' => $fastest,
        ];
    }

    /**
     * Get all active shipping rates grouped by courier for management UI.
     */
    public function getAllRates(): array
    {
        $providers = CourierProvider::where('is_active', true)->get()->keyBy('code');

        $rates = ShippingRate::with([])
            ->whereIn('courier_code', $providers->keys())
            ->orderBy('courier_code')
            ->orderBy('courier_zone')
            ->get();

        return $rates->map(function ($rate) use ($providers) {
            return [
                'id' => $rate->id,
                'courier_code' => $rate->courier_code,
                'courier_name' => $providers[$rate->courier_code]?->name ?? $rate->courier_code,
                'courier_zone' => $rate->courier_zone,
                'base_fee' => (float) $rate->base_fee,
                'per_kg_fee' => (float) $rate->per_kg_fee,
                'weight_threshold_kg' => (float) $rate->weight_threshold_kg,
                'cod_fee' => (float) $rate->cod_fee,
                'is_active' => $rate->is_active,
            ];
        })->toArray();
    }

    protected function getEstimatedDays(string $courierCode): ?int
    {
        return match (strtoupper($courierCode)) {
            'JNT' => 3,
            'FLASH' => 2,
            default => null,
        };
    }

    protected function findCheapest(array $rates): ?array
    {
        $withRates = array_filter($rates, fn ($r) => $r['has_rate'] && $r['total_fee'] > 0);
        if (empty($withRates)) {
            return null;
        }

        return array_reduce($withRates, fn ($carry, $item) => $carry === null || $item['total_fee'] < $carry['total_fee'] ? $item : $carry
        );
    }

    protected function findFastest(array $rates): ?array
    {
        $withRates = array_filter($rates, fn ($r) => $r['has_rate'] && $r['estimated_days'] !== null);
        if (empty($withRates)) {
            return null;
        }

        return array_reduce($withRates, fn ($carry, $item) => $carry === null || $item['estimated_days'] < $carry['estimated_days'] ? $item : $carry
        );
    }
}
