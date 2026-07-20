<?php

declare(strict_types=1);

namespace App\Domain\Shop\CourierCsv;

use App\Models\SiteSetting;

/**
 * Per-courier validation rule configuration.
 *
 * Defaults are provided per rule. Values can be overridden per courier
 * by storing JSON in SiteSetting under the key:
 *   courier_csv_validation_rules.{courier_code}
 */
final class CourierCsvValidationConfig
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private const DEFAULTS = [
        'phone' => [
            'enabled' => true,
            'min_length' => 10,
            'max_length' => 13,
        ],
        'cod_amount' => [
            'enabled' => true,
            'tolerance' => 0.01,
        ],
        'address' => [
            'enabled' => true,
            'min_address_length' => 5,
            'min_place_length' => 2,
            'require_letters' => true,
            'allow_po_box' => false,
            'flash_sender_checks' => true,
        ],
        'weight' => [
            'enabled' => true,
            'max_weight_kg' => 50.0,
        ],
        'dimensions' => [
            'enabled' => false,
            'max_length_cm' => 100.0,
            'max_width_cm' => 100.0,
            'max_height_cm' => 100.0,
        ],
    ];

    /**
     * Get merged validation configuration for a courier.
     *
     * @return array<string, array<string, mixed>>
     */
    public function get(string $courierCode): array
    {
        $stored = json_decode(
            SiteSetting::get("courier_csv_validation_rules.".strtoupper($courierCode), '{}') ?? '{}',
            true,
        ) ?: [];

        return $this->merge(self::DEFAULTS, $stored);
    }

    /**
     * Store validation configuration for a courier.
     *
     * @param array<string, array<string, mixed>> $rules
     */
    public function set(string $courierCode, array $rules): void
    {
        $current = $this->get($courierCode);

        SiteSetting::set(
            "courier_csv_validation_rules.".strtoupper($courierCode),
            json_encode($this->merge($current, $rules)),
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function merge(array $defaults, array $overrides): array
    {
        foreach ($overrides as $rule => $config) {
            if (! is_array($config)) {
                continue;
            }

            $defaults[$rule] = isset($defaults[$rule]) && is_array($defaults[$rule])
                ? array_merge($defaults[$rule], $config)
                : $config;
        }

        return $defaults;
    }
}
