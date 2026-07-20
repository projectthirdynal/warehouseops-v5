<?php

declare(strict_types=1);

namespace App\Domain\Shop\CourierCsv;

/**
 * Defines the complete CSV schema for a single courier.
 */
final class CourierCsvSchema
{
    /**
     * @param string $courierCode Courier code (e.g. 'JNT', 'FLASH')
     * @param string $name Display name (e.g. 'J&T Express', 'Flash Express')
     * @param array<int, CourierCsvColumn> $columns Ordered column definitions
     */
    public function __construct(
        public readonly string $courierCode,
        public readonly string $name,
        public readonly array $columns,
    ) {}

    /**
     * @return array<int, string>
     */
    public function headers(): array
    {
        return array_map(fn (CourierCsvColumn $col) => $col->header, $this->columns);
    }

    /**
     * @return array<string, string> field => label
     */
    public function requiredFields(): array
    {
        $required = [];

        foreach ($this->columns as $col) {
            if ($col->required && ! isset($required[$col->field])) {
                $required[$col->field] = $col->label();
            }
        }

        return $required;
    }

    /**
     * @return array<int, string>
     */
    public function requiredFieldLabels(): array
    {
        return array_values($this->requiredFields());
    }

    public function columnCount(): int
    {
        return count($this->columns);
    }

    /**
     * @return array<int, string>
     */
    public function fields(): array
    {
        return array_map(fn (CourierCsvColumn $col) => $col->field, $this->columns);
    }

    /**
     * Serialize to array for JSON responses.
     */
    public function toArray(): array
    {
        return [
            'courier_code' => $this->courierCode,
            'name' => $this->name,
            'column_count' => $this->columnCount(),
            'columns' => array_map(fn (CourierCsvColumn $col) => [
                'header' => $col->header,
                'field' => $col->field,
                'required' => $col->required,
                'label' => $col->label(),
            ], $this->columns),
            'required_fields' => $this->requiredFieldLabels(),
        ];
    }
}
