<?php

declare(strict_types=1);

namespace App\Domain\Courier\Services;

use App\Domain\Waybill\Enums\WaybillStatus;
use Illuminate\Support\Facades\Log;

class StatusMapper
{
    private array $maps = [];

    /**
     * Resolve a courier-specific status to an internal WaybillStatus.
     */
    public function resolve(string $courierCode, string|int $courierStatus): WaybillStatus
    {
        $map = $this->loadMap($courierCode);
        $key = (string) $courierStatus;

        if (!isset($map[$courierStatus]) && !isset($map[$key])) {
            Log::warning("StatusMapper: unknown {$courierCode} status '{$courierStatus}', falling back to PENDING");
            return WaybillStatus::PENDING;
        }

        return $map[$courierStatus] ?? $map[$key];
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
