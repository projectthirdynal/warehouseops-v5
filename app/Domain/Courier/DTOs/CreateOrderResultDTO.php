<?php

declare(strict_types=1);

namespace App\Domain\Courier\DTOs;

final readonly class CreateOrderResultDTO
{
    public function __construct(
        public bool    $success,
        public ?string $trackingNumber,
        public ?string $sortCode = null,
        public ?string $errorMessage = null,
        public array   $rawResponse = [],
    ) {}
}
