<?php

declare(strict_types=1);

namespace Modules\Shop\CourierCsv;

use Modules\Orders\Models\Order;
use Modules\Shop\Models\CourierExportRow;

/**
 * Performs courier-specific address format validation.
 *
 * Basic rules:
 *   - Complete address must be at least 10 characters and contain a letter.
 *   - No PO Box / P.O. Box style addresses.
 *   - Province, city, and barangay should contain letters and not be purely symbolic/numeric.
 *   - Flash Express additionally validates sender address fields.
 */
final class CourierCsvAddressValidator
{
    public function __construct(private readonly CourierCsvValidationConfig $config) {}

    /**
     * Validate an Order's address fields for a specific courier.
     *
     * @return array{valid: bool, errors: array<int, string>}
     */
    public function validateOrder(Order $order, string $courierCode): array
    {
        return $this->validateAddress(
            (string) $order->receiver_address,
            (string) $order->state,
            (string) $order->city,
            (string) $order->barangay,
            strtoupper($courierCode),
        );
    }

    /**
     * Validate a CourierExportRow's address fields.
     *
     * @return array{valid: bool, errors: array<int, string>}
     */
    public function validateRow(CourierExportRow $row, string $courierCode): array
    {
        return $this->validateAddress(
            (string) $row->complete_address,
            (string) $row->province,
            (string) $row->city,
            (string) $row->barangay,
            strtoupper($courierCode),
        );
    }

    /**
     * @return array{valid: bool, errors: array<int, string>}
     */
    private function validateAddress(
        string $completeAddress,
        string $province,
        string $city,
        string $barangay,
        string $courier,
    ): array {
        $rules = $this->config->get($courier)['address'] ?? [];

        if (($rules['enabled'] ?? true) === false) {
            return ['valid' => true, 'errors' => []];
        }

        $minAddressLength = (int) ($rules['min_address_length'] ?? 10);
        $minPlaceLength = (int) ($rules['min_place_length'] ?? 2);
        $requireLetters = (bool) ($rules['require_letters'] ?? true);
        $allowPoBox = (bool) ($rules['allow_po_box'] ?? false);
        $flashSenderChecks = (bool) ($rules['flash_sender_checks'] ?? true);

        $errors = [];

        $this->validateAddressField($completeAddress, 'complete_address', $errors, $minAddressLength, $requireLetters, $allowPoBox);
        $this->validatePlaceName($province, 'province', $errors, $minPlaceLength, $requireLetters);
        $this->validatePlaceName($city, 'city', $errors, $minPlaceLength, $requireLetters);
        $this->validatePlaceName($barangay, 'barangay', $errors, $minPlaceLength, $requireLetters);

        if ($courier === 'FLASH' && $flashSenderChecks) {
            $this->validateAddressField(
                (string) config('services.shop.sender_address'),
                'sender_address',
                $errors,
                $minAddressLength,
                $requireLetters,
                $allowPoBox,
            );
            $this->validatePlaceName((string) config('services.shop.sender_province'), 'sender_province', $errors, $minPlaceLength, $requireLetters);
            $this->validatePlaceName((string) config('services.shop.sender_city'), 'sender_city', $errors, $minPlaceLength, $requireLetters);
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<int, string>  $errors
     */
    private function validateAddressField(
        string $value,
        string $field,
        array &$errors,
        int $minLength,
        bool $requireLetters,
        bool $allowPoBox,
    ): void {
        if (trim($value) === '') {
            $errors[] = "{$field} is required.";

            return;
        }

        if (strlen($value) < $minLength) {
            $errors[] = "{$field} is too short (minimum {$minLength} characters).";
        }

        if ($requireLetters && ! preg_match('/[a-zA-Z]/', $value)) {
            $errors[] = "{$field} must contain letters.";
        }

        if (! $allowPoBox && preg_match('/\bP\.?\s*O\.?\s*Box\b/i', $value)) {
            $errors[] = "{$field} must not be a PO Box address.";
        }
    }

    /**
     * @param  array<int, string>  $errors
     */
    private function validatePlaceName(
        string $value,
        string $field,
        array &$errors,
        int $minLength,
        bool $requireLetters,
    ): void {
        if (trim($value) === '') {
            return;
        }

        if (strlen($value) < $minLength) {
            $errors[] = "{$field} is too short.";
        }

        if ($requireLetters && ! preg_match('/[a-zA-Z]/', $value)) {
            $errors[] = "{$field} must contain letters.";
        }
    }
}
