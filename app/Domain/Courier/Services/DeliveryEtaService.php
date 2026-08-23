<?php

declare(strict_types=1);

namespace App\Domain\Courier\Services;

use App\Domain\Courier\Models\ShippingDay;
use App\Domain\Shop\Models\AddressMapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class DeliveryEtaService
{
    /**
     * Fallback ETA in business days by island group (used when no shipping_days data exists).
     */
    private const FALLBACK_ETA_BY_ISLAND = [
        'Luzon' => 3,
        'Visayas' => 4,
        'Mindanao' => 5,
    ];

    /**
     * Heavy weather codes that may delay delivery.
     */
    private const HEAVY_WEATHER_CODES = [65, 66, 67, 75, 82, 86, 95, 96, 99];

    /**
     * Estimate delivery ETA for a given address.
     *
     * Uses the courier shipping_days table (46K+ barangay-level records) for accurate estimates.
     * Falls back to island-group-based estimates if no data is found.
     *
     * @return array{eta_days: int, eta_date: string, zone: string, island: string, weather_adjusted: bool, weather_condition: ?string, source: string}
     */
    public function estimateEta(?string $province, ?string $city = null, ?string $barangay = null): array
    {
        $mapping = $this->resolveAddressMapping($province, $city);

        $island = $mapping?->island_group ?? $this->guessIslandGroup($province);
        $zone = $mapping?->courier_zone ?? 'default';

        // Try to get exact shipping days from the courier data
        $baseEta = $this->getShippingDaysFromTable($province, $city, $barangay);
        $source = 'courier_table';

        if ($baseEta === null) {
            // Fall back to island-group estimate
            $baseEta = $this->getFallbackEta($island);
            $source = 'island_fallback';
        }

        // Check weather for destination
        $weatherAdjustment = 0;
        $weatherCondition = null;

        if ($mapping) {
            $weather = $this->getWeatherForLocation($mapping);
            if ($weather && in_array($weather['weather_code'], self::HEAVY_WEATHER_CODES)) {
                $weatherAdjustment = 1;
                $weatherCondition = $weather['condition'];
            }
        }

        $etaDays = $baseEta + $weatherAdjustment;
        $etaDate = $this->calculateEtaDate($etaDays);

        return [
            'eta_days' => $etaDays,
            'eta_date' => $etaDate->format('Y-m-d'),
            'zone' => $zone,
            'island' => $island,
            'weather_adjusted' => $weatherAdjustment > 0,
            'weather_condition' => $weatherCondition,
            'source' => $source,
        ];
    }

    /**
     * Look up shipping days from the courier shipping_days table.
     * Tries exact barangay match → city match → province match.
     */
    private function getShippingDaysFromTable(?string $province, ?string $city = null, ?string $barangay = null): ?int
    {
        if (! $province) {
            return null;
        }

        $shippingDay = ShippingDay::findForLocation($province, $city, $barangay);

        return $shippingDay?->shipping_days;
    }

    /**
     * Get fallback ETA based on island group.
     */
    private function getFallbackEta(string $island): int
    {
        return self::FALLBACK_ETA_BY_ISLAND[$island] ?? 5;
    }

    /**
     * Calculate the estimated delivery date, skipping Sundays.
     */
    private function calculateEtaDate(int $businessDays): \DateTime
    {
        $date = new \DateTime;
        $added = 0;

        while ($added < $businessDays) {
            $date->modify('+1 day');
            // Skip Sundays (day of week = 0)
            if ((int) $date->format('w') !== 0) {
                $added++;
            }
        }

        return $date;
    }

    /**
     * Try to find an AddressMapping for the given province/city.
     */
    private function resolveAddressMapping(?string $province, ?string $city): ?AddressMapping
    {
        if (! $province && ! $city) {
            return null;
        }

        $query = AddressMapping::query();

        if ($province && $city) {
            $query->where(function ($q) use ($province, $city) {
                $q->whereRaw('LOWER(province) LIKE ?', ['%'.mb_strtolower($province).'%'])
                    ->orWhereRaw('LOWER(city_municipality) LIKE ?', ['%'.mb_strtolower($city).'%']);
            });
        } elseif ($province) {
            $query->whereRaw('LOWER(province) LIKE ?', ['%'.mb_strtolower($province).'%']);
        } else {
            $query->whereRaw('LOWER(city_municipality) LIKE ?', ['%'.mb_strtolower($city).'%']);
        }

        return $query->first();
    }

    /**
     * Guess the island group from province name (fallback when no AddressMapping).
     */
    private function guessIslandGroup(?string $province): string
    {
        if (! $province) {
            return 'Luzon';
        }

        $p = mb_strtolower($province);

        // Visayas provinces
        $visayas = ['cebu', 'iloilo', 'negros', 'leyte', 'samar', 'bohol', 'capiz', 'aklan', 'antique', 'guimaras', 'masbate', 'romblon'];
        foreach ($visayas as $v) {
            if (str_contains($p, $v)) {
                return 'Visayas';
            }
        }

        // Mindanao provinces
        $mindanao = ['davao', 'cotabato', 'maguindanao', 'lanao', 'zamboanga', 'misamis', 'bukidnon', 'agusan', 'surigao', 'sultan', 'sulu', 'tawi', 'basilan', 'compostela', 'south cotabato'];
        foreach ($mindanao as $m) {
            if (str_contains($p, $m)) {
                return 'Mindanao';
            }
        }

        return 'Luzon';
    }

    /**
     * Get weather for a location via Open-Meteo API (cached 10 min).
     * Uses the Open-Meteo geocoding API to resolve lat/lon from city name.
     */
    private function getWeatherForLocation(AddressMapping $mapping): ?array
    {
        // AddressMapping doesn't have lat/lon columns — use geocoding API
        $cityName = $mapping->city_municipality ?? $mapping->province ?? null;

        if (! $cityName) {
            return null;
        }

        $cacheKey = "delivery_weather_geo_{$cityName}";

        return Cache::remember($cacheKey, 600, function () use ($cityName) {
            // First geocode the city name to lat/lon
            try {
                $geoResponse = Http::timeout(5)->get(
                    'https://geocoding-api.open-meteo.com/v1/search',
                    ['name' => $cityName, 'count' => 1, 'language' => 'en', 'format' => 'json']
                );

                if (! $geoResponse->ok()) {
                    return null;
                }

                $geoData = $geoResponse->json();
                $results = $geoData['results'] ?? [];

                if (empty($results)) {
                    return null;
                }

                $lat = $results[0]['latitude'];
                $lon = $results[0]['longitude'];
            } catch (\Throwable) {
                return null;
            }

            $url = sprintf(
                'https://api.open-meteo.com/v1/forecast?latitude=%s&longitude=%s&current=weather_code&timezone=Asia/Manila&forecast_days=1',
                $lat,
                $lon
            );

            try {
                $response = Http::timeout(5)->get($url);
                if (! $response->ok()) {
                    return null;
                }

                $data = $response->json();
                $code = $data['current']['weather_code'] ?? 0;

                $weatherCodeMap = [
                    0 => 'Clear sky', 1 => 'Mainly clear', 2 => 'Partly cloudy', 3 => 'Overcast',
                    45 => 'Fog', 48 => 'Fog',
                    51 => 'Light drizzle', 53 => 'Moderate drizzle', 55 => 'Dense drizzle',
                    61 => 'Slight rain', 63 => 'Moderate rain', 65 => 'Heavy rain',
                    71 => 'Slight snow', 73 => 'Moderate snow', 75 => 'Heavy snow',
                    80 => 'Rain showers', 81 => 'Rain showers', 82 => 'Violent rain showers',
                    95 => 'Thunderstorm', 96 => 'Thunderstorm', 99 => 'Thunderstorm',
                ];

                return [
                    'weather_code' => $code,
                    'condition' => $weatherCodeMap[$code] ?? 'Unknown',
                ];
            } catch (\Throwable) {
                return null;
            }
        });
    }

    /**
     * Get weather forecast for a given lat/lon (used by agent portal weather widget).
     *
     * @return array{available: bool, city?: string, temperature?: float, condition?: string, forecast?: array}
     */
    public function getWeatherForecast(float $lat, float $lon, string $cityName = ''): array
    {
        $cacheKey = "agent_weather_{$lat}_{$lon}";

        return Cache::remember($cacheKey, 600, function () use ($lat, $lon, $cityName) {
            $url = sprintf(
                'https://api.open-meteo.com/v1/forecast?latitude=%s&longitude=%s&current=temperature_2m,relative_humidity_2m,apparent_temperature,precipitation,weather_code,wind_speed_10m&daily=weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max&timezone=Asia/Manila&forecast_days=3',
                $lat,
                $lon
            );

            try {
                $response = Http::timeout(5)->get($url);
                if (! $response->ok()) {
                    return ['available' => false, 'city' => $cityName];
                }
                $data = $response->json();
            } catch (\Throwable) {
                return ['available' => false, 'city' => $cityName];
            }

            $current = $data['current'] ?? [];
            $daily = $data['daily'] ?? [];

            $weatherCodeMap = [
                0 => 'Clear sky', 1 => 'Mainly clear', 2 => 'Partly cloudy', 3 => 'Overcast',
                45 => 'Fog', 48 => 'Fog',
                51 => 'Light drizzle', 53 => 'Moderate drizzle', 55 => 'Dense drizzle',
                61 => 'Slight rain', 63 => 'Moderate rain', 65 => 'Heavy rain',
                71 => 'Slight snow', 73 => 'Moderate snow', 75 => 'Heavy snow',
                80 => 'Rain showers', 81 => 'Rain showers', 82 => 'Violent rain showers',
                95 => 'Thunderstorm', 96 => 'Thunderstorm', 99 => 'Thunderstorm',
            ];

            $code = $current['weather_code'] ?? 0;
            $forecast = [];
            $dailyCodes = $daily['weather_code'] ?? [];
            $dailyMax = $daily['temperature_2m_max'] ?? [];
            $dailyMin = $daily['temperature_2m_min'] ?? [];
            $dailyPrecip = $daily['precipitation_probability_max'] ?? [];
            $dailyDates = $daily['time'] ?? [];

            for ($i = 0; $i < min(3, count($dailyDates)); $i++) {
                $forecast[] = [
                    'date' => $dailyDates[$i],
                    'label' => date('D', strtotime($dailyDates[$i])),
                    'condition' => $weatherCodeMap[$dailyCodes[$i] ?? 0] ?? 'Unknown',
                    'weather_code' => $dailyCodes[$i] ?? 0,
                    'temp_max' => round($dailyMax[$i] ?? 0, 1),
                    'temp_min' => round($dailyMin[$i] ?? 0, 1),
                    'precip_prob' => (int) ($dailyPrecip[$i] ?? 0),
                ];
            }

            return [
                'available' => true,
                'city' => $cityName,
                'temperature' => round($current['temperature_2m'] ?? 0, 1),
                'feels_like' => round($current['apparent_temperature'] ?? 0, 1),
                'humidity' => (int) ($current['relative_humidity_2m'] ?? 0),
                'precipitation' => round($current['precipitation'] ?? 0, 1),
                'wind_speed' => round($current['wind_speed_10m'] ?? 0, 1),
                'condition' => $weatherCodeMap[$code] ?? 'Unknown',
                'weather_code' => $code,
                'is_raining' => in_array($code, [51, 53, 55, 56, 57, 61, 63, 65, 66, 67, 80, 81, 82, 95, 96, 99]),
                'forecast' => $forecast,
            ];
        });
    }
}
