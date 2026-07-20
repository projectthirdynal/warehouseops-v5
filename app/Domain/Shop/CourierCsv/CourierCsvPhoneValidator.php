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
    public function __construct(private readonly CourierCsvValidationConfig $config) {}

    /**
     * Minimum and maximum length for accepted mobile numbers.
     * National format: 09 + 9 digits = 11 chars.
     * International format: 63 + 10 digits = 12 digits or +63 + 10 digits = 13 chars.
     */
    private const MIN_LENGTH = 10;
    private const MAX_LENGTH = 13;

    /**
     * Validate a phone number and return a normalized value.
     *
     * @return array{valid: bool, normalized: string|null, error: string|null}
     */
    public function validate(?string $phone, ?string $courierCode = null): array
    {
        $rules = $this->config->get($courierCode ?? 'DEFAULT')['phone'] ?? [];

        if (($rules['enabled'] ?? true) === false) {
            return [
                'valid' => true,
                'normalized' => $phone,
                'error' => null,
            ];
        }

        $minLength = (int) ($rules['min_length'] ?? self::MIN_LENGTH);
        $maxLength = (int) ($rules['max_length'] ?? self::MAX_LENGTH);
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

        $normalized = $this->normalize($digits, $minLength, $maxLength);

        if ($normalized === null) {
            $length = strlen($digits);
            $expected = "{$minLength}-{$maxLength} digits"; 

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

    public function isValid(?string $phone, ?string $courierCode = null): bool
    {
        return $this->validate($phone, $courierCode)['valid'];
    }

    /**
     * Normalize phone to 09-prefixed 11-digit format.
     */
    public function normalize(?string $phone, int $minLength = self::MIN_LENGTH, int $maxLength = self::MAX_LENGTH): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $digits = $this->extractDigits($phone);

        if ($digits === '' || ! $this->isValidLength($digits, $minLength, $maxLength)) {
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

    private function isValidLength(string $digits, int $minLength = self::MIN_LENGTH, int $maxLength = self::MAX_LENGTH): bool
    {
        $length = strlen($digits);

        return $length >= $minLength && $length <= $maxLength;
    }
}
