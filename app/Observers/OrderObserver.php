<?php

declare(strict_types=1);

namespace App\Observers;

use App\Domain\Order\Models\Order;
use App\Jobs\RecalculateCustomerStats;
use App\Services\CustomerOrderCooldownService;

class OrderObserver
{
    public function saved(Order $order): void
    {
        if ($order->customer_id !== null) {
            RecalculateCustomerStats::dispatch($order->customer_id);

            $customer = $order->customer;
            if ($customer !== null) {
                app(CustomerOrderCooldownService::class)->syncCustomerLeads($customer);
            }
        }
    }

    public function deleted(Order $order): void
    {
        if ($order->customer_id !== null) {
            RecalculateCustomerStats::dispatch($order->customer_id);
        }
    }
}
