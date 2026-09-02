<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Modules\Inventory\Services\DeadStockScanService;
use Illuminate\Console\Command;

class ScanDeadStock extends Command
{
    protected $signature = 'inventory:scan-dead-stock';

    protected $description = 'Scan inventory for dead/slow/non-moving stock and auto-flag with notifications';

    public function handle(DeadStockScanService $service): int
    {
        $this->info('Scanning inventory for dead stock...');

        $result = $service->scan();

        $this->info("Scanned: {$result['total_scanned']} items ({$result['product_count']} products, {$result['supply_count']} supplies)");
        $this->info("Flagged: {$result['flagged_count']} items");

        foreach ($result['buckets'] as $bucket) {
            $this->line("  {$bucket['label']}: {$bucket['count']} items, ".number_format($bucket['total_value'], 2));
        }

        return self::SUCCESS;
    }
}
