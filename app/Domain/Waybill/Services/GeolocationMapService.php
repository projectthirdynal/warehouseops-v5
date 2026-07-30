<?php

declare(strict_types=1);

namespace App\Domain\Waybill\Services;

use App\Domain\Waybill\Enums\WaybillStatus;
use App\Models\Waybill;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeolocationMapService
{
    private const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';

    private array $cityCoordinateCache = [];

    public function getMapData(array $filters = []): array
    {
        $courier = $filters['courier'] ?? null;
        $status = $filters['status'] ?? null;

        $query = Waybill::query()
            ->whereIn('status', [
                WaybillStatus::DISPATCHED->value,
                WaybillStatus::PICKED_UP->value,
                WaybillStatus::IN_TRANSIT->value,
                WaybillStatus::ARRIVED_HUB->value,
                WaybillStatus::OUT_FOR_DELIVERY->value,
                WaybillStatus::DELIVERY_FAILED->value,
                WaybillStatus::RETURNING->value,
            ])
            ->whereNotNull('waybill_number')
            ->when($courier && $courier !== 'all', fn ($q) => $q->where('courier_provider', $courier))
            ->when($status && $status !== 'all', fn ($q) => $q->where('status', $status))
            ->with(['trackingHistory' => fn ($q) => $q->latest('tracked_at')->limit(5)])
            ->limit(500)
            ->get();

        $markers = [];
        $statusCounts = [];
        $courierCounts = [];
        $cities = [];

        foreach ($query as $waybill) {
            $coords = $this->resolveCoordinates($waybill);

            if ($coords) {
                $markers[] = [
                    'id' => $waybill->id,
                    'waybill_number' => $waybill->waybill_number,
                    'status' => $waybill->status,
                    'status_label' => $this->statusLabel($waybill->status),
                    'courier' => $waybill->courier_provider ?? 'UNKNOWN',
                    'receiver_name' => $waybill->receiver_name,
                    'receiver_phone' => $waybill->receiver_phone,
                    'city' => $waybill->city,
                    'address' => $waybill->receiver_address,
                    'cod_amount' => (float) ($waybill->cod_amount ?? $waybill->amount ?? 0),
                    'item_name' => $waybill->item_name,
                    'item_qty' => (int) $waybill->item_qty,
                    'lat' => $coords['lat'],
                    'lng' => $coords['lng'],
                    'location_description' => $waybill->last_location_description,
                    'last_location_at' => $waybill->last_location_at?->toIso8601String(),
                    'dispatched_at' => $waybill->dispatched_at?->toIso8601String(),
                    'tracking_history' => $waybill->trackingHistory->map(fn ($h) => [
                        'status' => $h->status,
                        'location' => $h->location,
                        'tracked_at' => $h->tracked_at?->toIso8601String(),
                    ])->toArray(),
                ];

                $statusCounts[$waybill->status] = ($statusCounts[$waybill->status] ?? 0) + 1;
                $courierCounts[$waybill->courier_provider ?? 'UNKNOWN'] = ($courierCounts[$waybill->courier_provider ?? 'UNKNOWN'] ?? 0) + 1;

                if ($waybill->city) {
                    $cities[$waybill->city] = ($cities[$waybill->city] ?? 0) + 1;
                }
            }
        }

        arsort($cities);
        $topCities = array_slice($cities, 0, 10, true);

        return [
            'markers' => $markers,
            'summary' => [
                'total_in_transit' => $query->count(),
                'total_mapped' => count($markers),
                'total_unmapped' => $query->count() - count($markers),
                'status_counts' => $statusCounts,
                'courier_counts' => $courierCounts,
                'top_cities' => $topCities,
            ],
            'filters' => $filters,
        ];
    }

    public function getWaybillLocationHistory(int $waybillId): array
    {
        $waybill = Waybill::with(['trackingHistory' => fn ($q) => $q->latest('tracked_at')->limit(50)])
            ->findOrFail($waybillId);

        $history = $waybill->trackingHistory->map(function ($h) {
            $coords = null;
            if ($h->latitude && $h->longitude) {
                $coords = ['lat' => (float) $h->latitude, 'lng' => (float) $h->longitude];
            } elseif ($h->location) {
                $coords = $this->geocodeLocation($h->location);
            }

            return [
                'id' => $h->id,
                'status' => $h->status,
                'previous_status' => $h->previous_status,
                'reason' => $h->reason,
                'location' => $h->location,
                'lat' => $coords['lat'] ?? null,
                'lng' => $coords['lng'] ?? null,
                'tracked_at' => $h->tracked_at?->toIso8601String(),
            ];
        })->toArray();

        return [
            'waybill' => [
                'id' => $waybill->id,
                'waybill_number' => $waybill->waybill_number,
                'status' => $waybill->status,
                'courier' => $waybill->courier_provider,
                'receiver_name' => $waybill->receiver_name,
                'city' => $waybill->city,
                'address' => $waybill->receiver_address,
            ],
            'history' => $history,
        ];
    }

    public function getStats(): array
    {
        $activeStatuses = [
            WaybillStatus::DISPATCHED->value,
            WaybillStatus::PICKED_UP->value,
            WaybillStatus::IN_TRANSIT->value,
            WaybillStatus::ARRIVED_HUB->value,
            WaybillStatus::OUT_FOR_DELIVERY->value,
        ];

        $total = Waybill::whereIn('status', $activeStatuses)->count();
        $withCoords = Waybill::whereIn('status', $activeStatuses)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->count();
        $withLocation = Waybill::whereIn('status', $activeStatuses)
            ->whereNotNull('last_location_description')
            ->count();

        $byCourier = Waybill::whereIn('status', $activeStatuses)
            ->selectRaw('courier_provider, count(*) as cnt')
            ->groupBy('courier_provider')
            ->pluck('cnt', 'courier_provider')
            ->toArray();

        $byStatus = Waybill::whereIn('status', $activeStatuses)
            ->selectRaw('status, count(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->toArray();

        $recentUpdates = Waybill::whereIn('status', $activeStatuses)
            ->whereNotNull('last_location_at')
            ->orderByDesc('last_location_at')
            ->limit(10)
            ->get(['id', 'waybill_number', 'courier_provider', 'status', 'last_location_description', 'last_location_at', 'city'])
            ->map(fn ($w) => [
                'id' => $w->id,
                'waybill_number' => $w->waybill_number,
                'courier' => $w->courier_provider,
                'status' => $w->status,
                'location' => $w->last_location_description,
                'last_location_at' => $w->last_location_at?->toIso8601String(),
                'city' => $w->city,
            ])
            ->toArray();

        return [
            'total_in_transit' => $total,
            'with_coordinates' => $withCoords,
            'with_location_text' => $withLocation,
            'coverage_percent' => $total > 0 ? round(($withCoords / $total) * 100, 1) : 0,
            'by_courier' => $byCourier,
            'by_status' => $byStatus,
            'recent_updates' => $recentUpdates,
        ];
    }

    private function resolveCoordinates(Waybill $waybill): ?array
    {
        if ($waybill->latitude && $waybill->longitude) {
            return ['lat' => (float) $waybill->latitude, 'lng' => (float) $waybill->longitude];
        }

        if ($waybill->last_location_description) {
            $coords = $this->geocodeLocation($waybill->last_location_description);
            if ($coords) {
                return $coords;
            }
        }

        if ($waybill->city) {
            return $this->geocodeCity($waybill->city);
        }

        return null;
    }

    private function geocodeCity(string $city): ?array
    {
        if (isset($this->cityCoordinateCache[$city])) {
            return $this->cityCoordinateCache[$city];
        }

        $coords = $this->geocodeNominatim($city . ', Philippines');
        $this->cityCoordinateCache[$city] = $coords;

        return $coords;
    }

    private function geocodeLocation(string $location): ?array
    {
        return $this->geocodeNominatim($location . ', Philippines');
    }

    private function geocodeNominatim(string $query): ?array
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'WarehouseOps/1.0',
            ])->timeout(5)->get(self::NOMINATIM_URL, [
                'q' => $query,
                'format' => 'json',
                'limit' => 1,
                'countrycodes' => 'ph',
            ]);

            if ($response->ok() && $response->json()) {
                $data = $response->json()[0];
                return [
                    'lat' => (float) $data['lat'],
                    'lng' => (float) $data['lon'],
                ];
            }
        } catch (\Exception $e) {
            Log::debug('Geocoding failed', ['query' => $query, 'error' => $e->getMessage()]);
        }

        return null;
    }

    private function statusLabel(string $status): string
    {
        $enum = WaybillStatus::tryFrom($status);
        return $enum?->label() ?? $status;
    }
}
