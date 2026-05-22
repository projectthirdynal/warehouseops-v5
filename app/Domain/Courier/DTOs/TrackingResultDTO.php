<?php

declare(strict_types=1);

namespace App\Domain\Courier\DTOs;

use App\Domain\Waybill\Enums\WaybillStatus;

final readonly class TrackingResultDTO
{
    public function __construct(
        public string         $waybillNumber,
        public WaybillStatus  $mappedStatus,
        public string         $courierStatus,
        public ?string        $location = null,
        public ?\DateTimeInterface $statusAt = null,
        public array          $rawData = [],
    ) {}
}
