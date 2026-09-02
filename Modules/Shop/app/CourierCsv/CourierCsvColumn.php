<?php

declare(strict_types=1);

namespace Modules\Shop\CourierCsv;

/**
 * Represents a single column in a courier CSV export schema.
 */
final class CourierCsvColumn
{
    /**
     * @param  string  $header  CSV header label
     * @param  string  $field  Canonical field name (e.g. 'receiver_name', 'phone_number', 'sender_name')
     * @param  bool  $required  Whether this field must be present and non-empty
     * @param  string|null  $label  Human-readable label for validation messages (defaults to header)
     */
    public function __construct(
        public readonly string $header,
        public readonly string $field,
        public readonly bool $required = false,
        public readonly ?string $label = null,
    ) {}

    public function label(): string
    {
        return $this->label ?? $this->header;
    }
}
