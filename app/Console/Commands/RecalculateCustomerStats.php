<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Modules\Shop\Services\CustomerStatsService;
use App\Models\Customer;
use Illuminate\Console\Command;

class RecalculateCustomerStats extends Command
{
    protected $signature = 'customers:recalculate-stats {--chunk=200}';

    protected $description = 'Recalculate order counters and success rate for all customers';

    public function handle(CustomerStatsService $stats): int
    {
        $chunk = (int) $this->option('chunk');
        $total = Customer::query()->count();

        $this->info("Recalculating stats for {$total} customers...");

        $processed = 0;
        Customer::query()->chunkById($chunk, function ($customers) use ($stats, &$processed, $total) {
            foreach ($customers as $customer) {
                $stats->recalculate($customer);
                $processed++;
            }
            $this->info("Processed {$processed}/{$total}");
        });

        $this->info('Done.');

        return self::SUCCESS;
    }
}
