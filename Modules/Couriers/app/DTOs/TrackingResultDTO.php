<?php

declare(strict_types=1);

namespace Modules\Couriers\DTOs;

use Modules\Waybills\Enums\WaybillStatus;

final readonly class TrackingResultDTO
{
    public function __construct(
        public string $waybillNumber,
        public WaybillStatus $mappedStatus,
        public string $courierStatus,
        public ?string $location = null,
        public ?\DateTimeInterface $statusAt = null,
        public array $rawData = [],
    ) {}
}
