<?php

declare(strict_types=1);

namespace App\Domain\Courier\Services;

use App\Domain\Waybill\Enums\WaybillStatus;

class StatusMapper
{
    private array $maps = [];

    /**
     * Resolve a courier-specific status to an internal WaybillStatus.
     */
    public function resolve(string $courierCode, string|int $courierStatus): WaybillStatus
    {
        $map = $this->loadMap($courierCode);

        return $map[$courierStatus] ?? WaybillStatus::PENDING;
    }

    private function loadMap(string $courierCode): array
    {
        $key = strtoupper($courierCode);

        if (!isset($this->maps[$key])) {
            $filename = match ($key) {
                'JNT'   => 'jnt_express.php',
                'FLASH' => 'flash_express.php',
                default => null,
            };

            if ($filename) {
                $path = __DIR__ . '/../StatusMaps/' . $filename;
                $this->maps[$key] = file_exists($path) ? require $path : [];
            } else {
                $this->maps[$key] = [];
            }
        }

        return $this->maps[$key];
    }
}
