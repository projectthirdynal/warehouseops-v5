<?php

declare(strict_types=1);

namespace Modules\Shop\CourierCsv;

use Illuminate\Support\Collection;
use Modules\Shop\Models\CourierCsvTemplate;

/**
 * Manages custom CSV templates per courier.
 * Converts stored template definitions into CourierCsvSchema objects for export.
 */
final class CourierCsvTemplateBuilder
{
    /**
     * Available fields that can be mapped to CSV columns.
     *
     * @return array<int, array{field: string, label: string}>
     */
    public function availableFields(): array
    {
        return [
            ['field' => 'order_number', 'label' => 'Order Number'],
            ['field' => 'receiver_name', 'label' => 'Receiver Name'],
            ['field' => 'phone_number', 'label' => 'Phone Number'],
            ['field' => 'complete_address', 'label' => 'Complete Address'],
            ['field' => 'province', 'label' => 'Province'],
            ['field' => 'city', 'label' => 'City'],
            ['field' => 'barangay', 'label' => 'Barangay'],
            ['field' => 'postal_code', 'label' => 'Postal Code'],
            ['field' => 'landmark', 'label' => 'Landmark'],
            ['field' => 'nearest_landmark', 'label' => 'Nearest Landmark'],
            ['field' => 'product_name', 'label' => 'Product Name'],
            ['field' => 'quantity', 'label' => 'Quantity'],
            ['field' => 'cod_amount', 'label' => 'COD Amount'],
            ['field' => 'remarks', 'label' => 'Remarks'],
            ['field' => 'sender_name', 'label' => 'Sender Name'],
            ['field' => 'sender_phone', 'label' => 'Sender Phone'],
            ['field' => 'sender_address', 'label' => 'Sender Address'],
            ['field' => 'sender_province', 'label' => 'Sender Province'],
            ['field' => 'sender_city', 'label' => 'Sender City'],
            ['field' => 'item_value', 'label' => 'Item Value'],
            ['field' => 'weight_kg', 'label' => 'Weight (kg)'],
        ];
    }

    /**
     * Get all custom templates, optionally filtered by courier.
     *
     * @return Collection<int, CourierCsvTemplate>
     */
    public function list(?string $courierCode = null, bool $activeOnly = true): Collection
    {
        $query = CourierCsvTemplate::query()->orderByDesc('created_at');

        if ($courierCode !== null) {
            $query->where('courier_code', strtoupper($courierCode));
        }

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        return $query->get();
    }

    public function find(int $id): ?CourierCsvTemplate
    {
        return CourierCsvTemplate::query()->find($id);
    }

    /**
     * Create a new custom template.
     *
     * @param  array<int, array{header: string, field: string, required: bool, label?: string}>  $columns
     */
    public function create(string $name, string $courierCode, array $columns, ?int $createdBy = null): CourierCsvTemplate
    {
        $this->validateColumns($columns);

        return CourierCsvTemplate::create([
            'name' => $name,
            'courier_code' => strtoupper($courierCode),
            'columns' => $columns,
            'is_active' => true,
            'created_by' => $createdBy,
        ]);
    }

    /**
     * Update an existing template.
     *
     * @param  array<int, array{header: string, field: string, required: bool, label?: string}>  $columns
     */
    public function update(int $id, string $name, array $columns, ?bool $isActive = null): ?CourierCsvTemplate
    {
        $template = CourierCsvTemplate::query()->find($id);

        if ($template === null) {
            return null;
        }

        $this->validateColumns($columns);

        $template->update([
            'name' => $name,
            'columns' => $columns,
            'is_active' => $isActive ?? $template->is_active,
        ]);

        return $template->fresh();
    }

    public function delete(int $id): bool
    {
        return CourierCsvTemplate::query()->where('id', $id)->delete() > 0;
    }

    /**
     * Convert a stored template into a CourierCsvSchema for export.
     */
    public function toSchema(CourierCsvTemplate $template): CourierCsvSchema
    {
        $columns = array_map(
            fn (array $col) => new CourierCsvColumn(
                header: $col['header'],
                field: $col['field'],
                required: $col['required'] ?? false,
                label: $col['label'] ?? null,
            ),
            $template->columns ?? [],
        );

        return new CourierCsvSchema(
            courierCode: $template->courier_code,
            name: $template->name,
            columns: $columns,
        );
    }

    /**
     * Get the active custom schema for a courier, or null if none exists.
     */
    public function resolveSchema(string $courierCode): ?CourierCsvSchema
    {
        $template = CourierCsvTemplate::query()
            ->where('courier_code', strtoupper($courierCode))
            ->where('is_active', true)
            ->latest()
            ->first();

        if ($template === null) {
            return null;
        }

        return $this->toSchema($template);
    }

    /**
     * @param  array<int, array{header: string, field: string, required: bool, label?: string}>  $columns
     */
    private function validateColumns(array $columns): void
    {
        if (count($columns) === 0) {
            throw new \InvalidArgumentException('Template must have at least one column.');
        }

        $validFields = array_column($this->availableFields(), 'field');

        foreach ($columns as $i => $col) {
            if (empty($col['header'])) {
                throw new \InvalidArgumentException("Column {$i}: header is required.");
            }

            if (empty($col['field']) || ! in_array($col['field'], $validFields, true)) {
                throw new \InvalidArgumentException("Column {$i}: field '{$col['field']}' is not a valid field.");
            }
        }
    }
}
