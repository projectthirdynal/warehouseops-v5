<?php

declare(strict_types=1);

namespace App\Domain\Shop\Services;

use Illuminate\Support\Facades\Http;

class GeocodingService
{
    private const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';

    /**
     * Geocode an address string using Nominatim (OpenStreetMap).
     *
     * @return array{
     *     success: bool,
     *     latitude: ?float,
     *     longitude: ?float,
     *     display_name: ?string,
     *     components: array<string, ?string>,
     * }
     */
    public function geocode(string $address): array
    {
        if (trim($address) === '') {
            return [
                'success' => false,
                'latitude' => null,
                'longitude' => null,
                'display_name' => null,
                'components' => [],
            ];
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'WarehouseOps/1.0',
            ])->timeout(10)->get(self::NOMINATIM_URL, [
                'q' => $address,
                'format' => 'json',
                'addressdetails' => 1,
                'limit' => 1,
                'countrycodes' => 'ph',
            ]);

            if (! $response->ok()) {
                return $this->emptyResult();
            }

            $data = $response->json();
            if (empty($data) || ! isset($data[0])) {
                return $this->emptyResult();
            }

            $hit = $data[0];
            $addr = $hit['address'] ?? [];

            $components = [
                'province' => $addr['state'] ?? null,
                'city_municipality' => $addr['city'] ?? $addr['town'] ?? $addr['municipality'] ?? null,
                'barangay' => $addr['suburb'] ?? $addr['village'] ?? $addr['barangay'] ?? null,
                'postal_code' => $addr['postcode'] ?? null,
            ];

            return [
                'success' => true,
                'latitude' => isset($hit['lat']) ? (float) $hit['lat'] : null,
                'longitude' => isset($hit['lon']) ? (float) $hit['lon'] : null,
                'display_name' => $hit['display_name'] ?? null,
                'components' => $components,
            ];
        } catch (\Throwable $e) {
            return $this->emptyResult();
        }
    }

    private function emptyResult(): array
    {
        return [
            'success' => false,
            'latitude' => null,
            'longitude' => null,
            'display_name' => null,
            'components' => [],
        ];
    }
}
