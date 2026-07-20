<?php

declare(strict_types=1);

namespace App\Domain\Shop\CourierCsv;

use App\Domain\Order\Models\Order;
use App\Domain\Shop\Models\CourierExportRow;

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
    private const MIN_ADDRESS_LENGTH = 10;
    private const MIN_PLACE_LENGTH = 2;

    /**
     * Validate an Order's address fields for a specific courier.
     *
     * @return array{valid: bool, errors: array<int, string>}
     */
    public function validateOrder(Order $order, string $courierCode): array
    {
        $errors = [];
        $courier = strtoupper($courierCode);

        $this->validateAddressField((string) $order->receiver_address, 'complete_address', $errors);
        $this->validatePlaceName((string) $order->state, 'province', $errors);
        $this->validatePlaceName((string) $order->city, 'city', $errors);
        $this->validatePlaceName((string) $order->barangay, 'barangay', $errors);

        if ($courier === 'FLASH') {
            $this->validateAddressField((string) config('services.shop.sender_address'), 'sender_address', $errors);
            $this->validatePlaceName((string) config('services.shop.sender_province'), 'sender_province', $errors);
            $this->validatePlaceName((string) config('services.shop.sender_city'), 'sender_city', $errors);
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }

    /**
     * Validate a CourierExportRow's address fields.
     *
     * @return array{valid: bool, errors: array<int, string>}
     */
    public function validateRow(CourierExportRow $row, string $courierCode): array
    {
        $errors = [];
        $courier = strtoupper($courierCode);

        $this->validateAddressField((string) $row->complete_address, 'complete_address', $errors);
        $this->validatePlaceName((string) $row->province, 'province', $errors);
        $this->validatePlaceName((string) $row->city, 'city', $errors);
        $this->validatePlaceName((string) $row->barangay, 'barangay', $errors);

        if ($courier === 'FLASH') {
            $this->validateAddressField((string) config('services.shop.sender_address'), 'sender_address', $errors);
            $this->validatePlaceName((string) config('services.shop.sender_province'), 'sender_province', $errors);
            $this->validatePlaceName((string) config('services.shop.sender_city'), 'sender_city', $errors);
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }

    /**
     * @param array<int, string> $errors
     */
    private function validateAddressField(string $value, string $field, array &$errors): void
    {
        if (trim($value) === '') {
            $errors[] = "{$field} is required.";

            return;
        }

        if (strlen($value) < self::MIN_ADDRESS_LENGTH) {
            $errors[] = "{$field} is too short (minimum " . self::MIN_ADDRESS_LENGTH . ' characters).';
        }

        if (! preg_match('/[a-zA-Z]/', $value)) {
            $errors[] = "{$field} must contain letters.";
        }

        if (preg_match('/\bP\.?\s*O\.?\s*Box\b/i', $value)) {
            $errors[] = "{$field} must not be a PO Box address.";
        }
    }

    /**
     * @param array<int, string> $errors
     */
    private function validatePlaceName(string $value, string $field, array &$errors): void
    {
        if (trim($value) === '') {
            return;
        }

        if (strlen($value) < self::MIN_PLACE_LENGTH) {
            $errors[] = "{$field} is too short.";
        }

        if (! preg_match('/[a-zA-Z]/', $value)) {
            $errors[] = "{$field} must contain letters.";
        }
    }
}
