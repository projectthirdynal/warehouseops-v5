<?php

declare(strict_types=1);

namespace Modules\Couriers\Services;

use Modules\Couriers\Contracts\CourierServiceInterface;
use Modules\Couriers\Models\CourierProvider;

class CourierServiceManager
{
    /**
     * Resolve the correct courier service by code.
     */
    public function driver(string $code): CourierServiceInterface
    {
        $normalized = strtoupper(str_replace('&', '', $code)); // J&T → JT

        return match ($normalized) {
            'FLASH' => app(FlashExpressService::class),
            'JNT', 'JT' => app(JntExpressService::class),
            'MOCK' => app(MockCourierService::class),
            default => throw new \InvalidArgumentException("Unknown courier: {$code}"),
        };
    }

    /**
     * Resolve from a CourierProvider model.
     */
    public function forProvider(CourierProvider $provider): CourierServiceInterface
    {
        return $this->driver($provider->code);
    }

    /**
     * Get all available courier codes (excluding MANUAL).
     */
    public function availableCodes(): array
    {
        return ['FLASH', 'JNT'];
    }
}
