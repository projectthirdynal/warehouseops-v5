<?php

declare(strict_types=1);

namespace App\Observers;

use App\Domain\Order\Models\Order;
use App\Domain\Shop\Services\CustomerStatsService;

class OrderObserver
{
    public function __construct(private readonly CustomerStatsService $stats) {}

    public function saved(Order $order): void
    {
        if ($order->customer_id !== null) {
            $this->stats->recalculate($order->customer);
        }
    }

    public function deleted(Order $order): void
    {
        if ($order->customer_id !== null && $order->customer !== null) {
            $this->stats->recalculate($order->customer);
        }
    }
}
