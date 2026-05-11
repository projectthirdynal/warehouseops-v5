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
}
