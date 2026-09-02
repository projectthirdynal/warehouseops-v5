<?php

declare(strict_types=1);

namespace App\Jobs;

use Modules\Shop\Services\CustomerStatsService;
use App\Models\Customer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecalculateCustomerStats implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        public readonly int $customerId,
    ) {}

    public function handle(CustomerStatsService $stats): void
    {
        $customer = Customer::find($this->customerId);
        if ($customer) {
            $stats->recalculate($customer);
        }
    }
}
