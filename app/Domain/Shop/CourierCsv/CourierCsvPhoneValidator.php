<?php

declare(strict_types=1);

namespace App\Domain\Shop\CourierCsv;

/**
 * Validates and normalizes Philippine mobile phone numbers for courier CSVs.
 *
 * Accepts common formats such as:
 *   09171234567
 *   0917 123 4567
 *   +63 917 123 4567
 *   63 917 123 4567
 *
 * Produces an 11-digit national format starting with 09.
 */
final class CourierCsvPhoneValidator
{
    /**
     * Minimum and maximum length for accepted mobile numbers.
     * National format: 09 + 9 digits = 11 chars.
     * International format: 63 + 10 digits = 12 digits or +63 + 10 digits = 13 chars.
     */
    public const MIN_LENGTH = 10;
    public const MAX_LENGTH = 13;

    /**
     * Validate a phone number and return a normalized value.
     *
     * @return array{valid: bool, normalized: string|null, error: string|null}
     */
    public function validate(?string $phone): array
    {
        if ($phone === null || trim($phone) === '') {
            return [
                'valid' => false,
                'normalized' => null,
                'error' => 'Phone number is required.',
            ];
        }

        $digits = $this->extractDigits($phone);

        if ($digits === '') {
            return [
                'valid' => false,
                'normalized' => null,
                'error' => 'Phone number must contain digits.',
            ];
        }

        $normalized = $this->normalize($digits);

        if ($normalized === null) {
            $length = strlen($digits);
            $expected = '11 digits starting with 09, or 12-13 digits starting with +63/63';

            return [
                'valid' => false,
                'normalized' => null,
                'error' => "Invalid phone number format. Expected {$expected}. Received {$length} digits.",
            ];
        }

        return [
            'valid' => true,
            'normalized' => $normalized,
            'error' => null,
        ];
    }

    public function isValid(?string $phone): bool
    {
        return $this->validate($phone)['valid'];
    }

    /**
     * Normalize phone to 09-prefixed 11-digit format.
     */
    public function normalize(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $digits = $this->extractDigits($phone);

        if ($digits === '' || ! $this->isValidLength($digits)) {
            return null;
        }

        return match (true) {
            str_starts_with($digits, '09') && strlen($digits) === 11 => $digits,
            str_starts_with($digits, '63') && strlen($digits) === 12 => '0' . substr($digits, 2),
            str_starts_with($digits, '0') && strlen($digits) === 11 => $digits,
            default => null,
        };
    }

    /**
     * Strip all non-digit characters.
     */
    public function extractDigits(?string $phone): string
    {
        if ($phone === null) {
            return '';
        }

        return preg_replace('/[^0-9]/', '', $phone) ?? '';
    }

    private function isValidLength(string $digits): bool
    {
        $length = strlen($digits);

        return $length >= self::MIN_LENGTH && $length <= self::MAX_LENGTH;
    }
}
