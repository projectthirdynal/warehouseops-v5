<?php

declare(strict_types=1);

namespace App\Domain\Shop\Services;

class PhoneDetectionService
{
    /**
     * Extract and normalize Philippine mobile phone numbers from free text.
     *
     * @return array<int, string>
     */
    public function extract(string $text): array
    {
        preg_match_all('/(?:\+?63|0)?9(?:[\s.-]*\d){9}/', $text, $matches);

        return collect($matches[0] ?? [])
            ->map(fn (string $candidate) => $this->normalize($candidate))
            ->filter(fn (string $candidate) => $this->isValidMobile($candidate))
            ->unique()
            ->values()
            ->all();
    }

    public function normalize(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '63') && strlen($digits) === 12) {
            return '0' . substr($digits, 2);
        }

        if (str_starts_with($digits, '9') && strlen($digits) === 10) {
            return '0' . $digits;
        }

        return $digits;
    }

    public function isValidMobile(string $phone): bool
    {
        return preg_match('/^09\d{9}$/', $phone) === 1;
    }
}
