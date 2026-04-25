<?php

declare(strict_types=1);

namespace App\Domain\Finance\Exceptions;

use RuntimeException;

class CogsMethodChangeException extends RuntimeException
{
    public function __construct(string $message = 'COGS method is locked. Switching mid-period requires superadmin override and an audit log entry.')
    {
        parent::__construct($message);
    }
}

class InsufficientCostLotsException extends RuntimeException
{
    public function __construct(int $productId, float $requested, float $available)
    {
        parent::__construct(
            "Insufficient FIFO cost lots for product {$productId}: needed {$requested}, available {$available}."
        );
    }
}
