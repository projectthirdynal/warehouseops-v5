<?php

declare(strict_types=1);

namespace App\Domain\Shop\Services;

use App\Domain\Shop\Models\AddressMapping;

class AddressMappingService
{
    /**
     * @param array{province?: ?string, city_municipality?: ?string, barangay?: ?string, address?: ?string} $input
     * @return array{mapping: ?AddressMapping, confidence: float, requires_encoder_review: bool}
     */
    public function match(array $input): array
    {
        $province = $this->normalizeText($input['province'] ?? '');
        $city = $this->normalizeText($input['city_municipality'] ?? '');
        $barangay = $this->normalizeText($input['barangay'] ?? '');
        $address = $this->normalizeText($input['address'] ?? '');

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

        if ($mapping) {
            $confidence = $barangay !== '' ? 95.0 : ($city !== '' ? 85.0 : 70.0);

            return [
                'mapping' => $mapping,
                'confidence' => $confidence,
                'requires_encoder_review' => $confidence < 90.0,
            ];
        }

        if ($address !== '') {
            $mapping = AddressMapping::query()
                ->get()
                ->first(fn (AddressMapping $mapping) => str_contains($address, $this->normalizeText($mapping->province))
                    || str_contains($address, $this->normalizeText($mapping->city_municipality)));

            if ($mapping) {
                return [
                    'mapping' => $mapping,
                    'confidence' => 55.0,
                    'requires_encoder_review' => true,
                ];
            }
        }

        return [
            'mapping' => null,
            'confidence' => 0.0,
            'requires_encoder_review' => true,
        ];
    }

    private function normalizeText(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    /**
     * Validate individual address fields against the mapping table.
     *
     * @param array{province?: ?string, city_municipality?: ?string, barangay?: ?string} $input
     * @return array{
     *     province: array{valid: bool, suggestions: string[]},
     *     city_municipality: array{valid: bool, suggestions: string[]},
     *     barangay: array{valid: bool, suggestions: string[]},
     *     overall_valid: bool,
     * }
     */
    public function validate(array $input): array
    {
        $province = $this->normalizeText($input['province'] ?? '');
        $city = $this->normalizeText($input['city_municipality'] ?? '');
        $barangay = $this->normalizeText($input['barangay'] ?? '');

        $provinceValid = false;
        $provinceSuggestions = [];

        if ($province !== '') {
            $provinceMatch = AddressMapping::query()
                ->whereRaw('LOWER(province) = ?', [$province])
                ->exists();
            $provinceValid = $provinceMatch;

            if (! $provinceValid) {
                $provinceSuggestions = AddressMapping::query()
                    ->select('province')
                    ->distinct()
                    ->get()
                    ->pluck('province')
                    ->filter(fn ($p) => str_starts_with($this->normalizeText($p), substr($province, 0, 3)))
                    ->take(5)
                    ->values()
                    ->toArray();
            }
        }

        $cityValid = false;
        $citySuggestions = [];

        if ($city !== '') {
            $cityQuery = AddressMapping::query()
                ->whereRaw('LOWER(city_municipality) = ?', [$city]);
            if ($province !== '') {
                $cityQuery->whereRaw('LOWER(province) = ?', [$province]);
            }
            $cityValid = $cityQuery->exists();

            if (! $cityValid) {
                $suggestQuery = AddressMapping::query()
                    ->select('city_municipality')
                    ->distinct();
                if ($province !== '') {
                    $suggestQuery->whereRaw('LOWER(province) = ?', [$province]);
                }
                $citySuggestions = $suggestQuery
                    ->get()
                    ->pluck('city_municipality')
                    ->filter(fn ($c) => str_starts_with($this->normalizeText($c), substr($city, 0, 3)))
                    ->take(5)
                    ->values()
                    ->toArray();
            }
        }

        $barangayValid = false;
        $barangaySuggestions = [];

        if ($barangay !== '') {
            $barangayQuery = AddressMapping::query()
                ->whereRaw('LOWER(COALESCE(barangay, \'\')) = ?', [$barangay]);
            if ($province !== '') {
                $barangayQuery->whereRaw('LOWER(province) = ?', [$province]);
            }
            if ($city !== '') {
                $barangayQuery->whereRaw('LOWER(city_municipality) = ?', [$city]);
            }
            $barangayValid = $barangayQuery->exists();

            if (! $barangayValid) {
                $suggestQuery = AddressMapping::query()
                    ->select('barangay')
                    ->distinct()
                    ->whereNotNull('barangay');
                if ($province !== '') {
                    $suggestQuery->whereRaw('LOWER(province) = ?', [$province]);
                }
                if ($city !== '') {
                    $suggestQuery->whereRaw('LOWER(city_municipality) = ?', [$city]);
                }
                $barangaySuggestions = $suggestQuery
                    ->get()
                    ->pluck('barangay')
                    ->filter(fn ($b) => str_starts_with($this->normalizeText($b), substr($barangay, 0, 3)))
                    ->take(5)
                    ->values()
                    ->toArray();
            }
        }

        $overallValid = ($province === '' || $provinceValid)
            && ($city === '' || $cityValid)
            && ($barangay === '' || $barangayValid);

        return [
            'province' => ['valid' => $provinceValid, 'suggestions' => $provinceSuggestions],
            'city_municipality' => ['valid' => $cityValid, 'suggestions' => $citySuggestions],
            'barangay' => ['valid' => $barangayValid, 'suggestions' => $barangaySuggestions],
            'overall_valid' => $overallValid,
        ];
    }
}
