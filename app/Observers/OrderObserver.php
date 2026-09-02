<?php

declare(strict_types=1);

namespace App\Observers;

use Modules\Orders\Models\Order;
use App\Jobs\RecalculateCustomerStats;

class OrderObserver
{
    public function saved(Order $order): void
    {
        if ($order->customer_id !== null) {
            RecalculateCustomerStats::dispatch($order->customer_id);
        }
    }

    public function deleted(Order $order): void
    {
        if ($order->customer_id !== null) {
            RecalculateCustomerStats::dispatch($order->customer_id);
        }
    }
}
