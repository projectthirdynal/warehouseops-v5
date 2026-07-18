<?php

declare(strict_types=1);

namespace App\Domain\Shop\Services;

use App\Domain\Shop\Models\AddressMapping;

class AddressMappingService
{
    /**
     * Autocomplete address fields from mapped data.
     *
     * @param array{field: string, q: string, province?: ?string, city_municipality?: ?string} $input
     * @return string[]
     */
    public function autocomplete(array $input): array
    {
        $field = $input['field'] ?? '';
        $q = $this->normalizeText($input['q'] ?? '');
        $province = $this->normalizeText($input['province'] ?? '');
        $city = $this->normalizeText($input['city_municipality'] ?? '');

        if ($q === '' || !in_array($field, ['province', 'city_municipality', 'barangay'], true)) {
            return [];
        }

        return match ($field) {
            'province' => AddressMapping::query()
                ->select('province')
                ->distinct()
                ->whereRaw('LOWER(province) LIKE ?', ["%{$q}%"])
                ->orderBy('province')
                ->limit(10)
                ->pluck('province')
                ->toArray(),
            'city_municipality' => AddressMapping::query()
                ->select('city_municipality')
                ->distinct()
                ->when($province !== '', fn ($query) => $query->whereRaw('LOWER(province) = ?', [$province]))
                ->whereRaw('LOWER(city_municipality) LIKE ?', ["%{$q}%"])
                ->orderBy('city_municipality')
                ->limit(10)
                ->pluck('city_municipality')
                ->toArray(),
            'barangay' => AddressMapping::query()
                ->select('barangay')
                ->whereNotNull('barangay')
                ->when($province !== '', fn ($query) => $query->whereRaw('LOWER(province) = ?', [$province]))
                ->when($city !== '', fn ($query) => $query->whereRaw('LOWER(city_municipality) = ?', [$city]))
                ->whereRaw('LOWER(barangay) LIKE ?', ["%{$q}%"])
                ->orderBy('barangay')
                ->limit(10)
                ->pluck('barangay')
                ->toArray(),
            default => [],
        };
    }
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
     * Suggest corrections for misspelled address fields using fuzzy matching.
     *
     * @param array{field: string, q: string, province?: ?string, city_municipality?: ?string} $input
     * @return string[]
     */
    public function suggestCorrections(array $input): array
    {
        $field = $input['field'] ?? '';
        $q = $this->normalizeText($input['q'] ?? '');
        $province = $this->normalizeText($input['province'] ?? '');
        $city = $this->normalizeText($input['city_municipality'] ?? '');

        if ($q === '' || !in_array($field, ['province', 'city_municipality', 'barangay'], true)) {
            return [];
        }

        return match ($field) {
            'province' => $this->fuzzyMatch(
                AddressMapping::query()->select('province')->distinct()->pluck('province')->toArray(),
                $q,
            ),
            'city_municipality' => $this->fuzzyMatch(
                AddressMapping::query()
                    ->select('city_municipality')
                    ->distinct()
                    ->when($province !== '', fn ($query) => $query->whereRaw('LOWER(province) = ?', [$province]))
                    ->pluck('city_municipality')
                    ->toArray(),
                $q,
            ),
            'barangay' => $this->fuzzyMatch(
                AddressMapping::query()
                    ->select('barangay')
                    ->whereNotNull('barangay')
                    ->when($province !== '', fn ($query) => $query->whereRaw('LOWER(province) = ?', [$province]))
                    ->when($city !== '', fn ($query) => $query->whereRaw('LOWER(city_municipality) = ?', [$city]))
                    ->pluck('barangay')
                    ->toArray(),
                $q,
            ),
            default => [],
        };
    }

    /**
     * @param string[] $candidates
     * @return string[]
     */
    private function fuzzyMatch(array $candidates, string $query, int $threshold = 3): array
    {
        $scored = [];
        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeText($candidate);
            $distance = levenshtein($query, $normalized);
            if ($distance <= $threshold) {
                $scored[$candidate] = $distance;
            }
        }
        asort($scored);
        return array_keys(array_slice($scored, 0, 5, true));
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
                $candidates = AddressMapping::query()
                    ->select('province')
                    ->distinct()
                    ->pluck('province')
                    ->toArray();
                $provinceSuggestions = $this->fuzzyMatch($candidates, $province);
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
                $citySuggestions = $this->fuzzyMatch(
                    $suggestQuery->pluck('city_municipality')->toArray(),
                    $city,
                );
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
                $barangaySuggestions = $this->fuzzyMatch(
                    $suggestQuery->pluck('barangay')->toArray(),
                    $barangay,
                );
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
