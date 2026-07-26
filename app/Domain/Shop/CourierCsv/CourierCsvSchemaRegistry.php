<?php

declare(strict_types=1);

namespace App\Domain\Shop\CourierCsv;

/**
 * Registry of all courier CSV schemas.
 * Provides lookup by courier code and supports custom registration.
 */
final class CourierCsvSchemaRegistry
{
    /** @var array<string, CourierCsvSchema> */
    private array $schemas = [];

    public function __construct()
    {
        $this->registerDefaults();
    }

    public function register(CourierCsvSchema $schema): void
    {
        $this->schemas[strtoupper($schema->courierCode)] = $schema;
    }

    public function get(string $courierCode): ?CourierCsvSchema
    {
        return $this->schemas[strtoupper($courierCode)] ?? null;
    }

    /**
     * @return array<string, CourierCsvSchema>
     */
    public function all(): array
    {
        return $this->schemas;
    }

    /**
     * @return array<int, string>
     */
    public function codes(): array
    {
        return array_keys($this->schemas);
    }

    public function has(string $courierCode): bool
    {
        return isset($this->schemas[strtoupper($courierCode)]);
    }

    /**
     * Get schema or fall back to the generic schema.
     */
    public function resolve(string $courierCode): CourierCsvSchema
    {
        return $this->get($courierCode) ?? $this->schemas['GENERIC'];
    }

    private function registerDefaults(): void
    {
        $this->register(new CourierCsvSchema(
            courierCode: 'JNT',
            name: 'J&T Express',
            columns: [
                new CourierCsvColumn('Order Number', 'order_number'),
                new CourierCsvColumn('Receiver Name', 'receiver_name', required: true),
                new CourierCsvColumn('Receiver Mobile', 'phone_number', required: true, label: 'phone number'),
                new CourierCsvColumn('Receiver Address', 'complete_address', required: true, label: 'complete address'),
                new CourierCsvColumn('Province', 'province', required: true),
                new CourierCsvColumn('City', 'city', required: true),
                new CourierCsvColumn('Barangay', 'barangay', required: true),
                new CourierCsvColumn('Item Name', 'product_name', required: true, label: 'product'),
                new CourierCsvColumn('Quantity', 'quantity', required: true),
                new CourierCsvColumn('COD Amount', 'cod_amount', required: true, label: 'COD amount'),
                new CourierCsvColumn('Item Value', 'cod_amount', required: false),
                new CourierCsvColumn('Remark', 'remarks', required: false),
            ],
        ));

        $this->register(new CourierCsvSchema(
            courierCode: 'FLASH',
            name: 'Flash Express',
            columns: [
                new CourierCsvColumn('Order Number', 'order_number'),
                new CourierCsvColumn('Sender Name', 'sender_name'),
                new CourierCsvColumn('Sender Mobile', 'sender_phone'),
                new CourierCsvColumn('Sender Address', 'sender_address'),
                new CourierCsvColumn('Sender Province', 'sender_province'),
                new CourierCsvColumn('Sender City', 'sender_city'),
                new CourierCsvColumn('Consignee Name', 'receiver_name', required: true),
                new CourierCsvColumn('Consignee Mobile', 'phone_number', required: true, label: 'phone number'),
                new CourierCsvColumn('Consignee Address', 'complete_address', required: true, label: 'complete address'),
                new CourierCsvColumn('Province', 'province', required: true),
                new CourierCsvColumn('City', 'city', required: true),
                new CourierCsvColumn('Barangay', 'barangay', required: true),
                new CourierCsvColumn('Goods Name', 'product_name', required: true, label: 'product'),
                new CourierCsvColumn('Quantity', 'quantity', required: true),
                new CourierCsvColumn('COD Amount', 'cod_amount', required: true, label: 'COD amount'),
                new CourierCsvColumn('Remark', 'remarks', required: false),
            ],
        ));

        $this->register(new CourierCsvSchema(
            courierCode: 'GENERIC',
            name: 'Generic CSV',
            columns: [
                new CourierCsvColumn('Order Number', 'order_number'),
                new CourierCsvColumn('Receiver Name', 'receiver_name', required: true),
                new CourierCsvColumn('Phone Number', 'phone_number', required: true, label: 'phone number'),
                new CourierCsvColumn('Complete Address', 'complete_address', required: true, label: 'complete address'),
                new CourierCsvColumn('Province', 'province', required: false),
                new CourierCsvColumn('City', 'city', required: false),
                new CourierCsvColumn('Barangay', 'barangay', required: false),
                new CourierCsvColumn('Product Name', 'product_name', required: true, label: 'product'),
                new CourierCsvColumn('Quantity', 'quantity', required: true),
                new CourierCsvColumn('COD Amount', 'cod_amount', required: true, label: 'COD amount'),
                new CourierCsvColumn('Remarks', 'remarks', required: false),
            ],
        ));
    }
}
