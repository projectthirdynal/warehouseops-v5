<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use RuntimeException;

class InsufficientStockException extends RuntimeException
{
    public function __construct(
        public readonly int $productId,
        public readonly int $requested,
        public readonly int $available,
        ?string $message = null,
    ) {
        parent::__construct(
            $message ?? "Insufficient stock for product {$productId}: requested {$requested}, available {$available}."
        );
    }
}
