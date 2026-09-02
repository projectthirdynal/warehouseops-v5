<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Modules\Inventory\Services\StockStatusService;
use Illuminate\Console\Command;

class RecomputeStockStatus extends Command
{
    protected $signature = 'inventory:recompute-stock-status';

    protected $description = 'Auto-classify supply stock_status (MOVING/NON_MOVING/DEAD) based on last_movement_at';

    public function handle(StockStatusService $service): int
    {
        $this->info('Recomputing supply stock statuses...');
        $updated = $service->recomputeAll();
        $this->info("Done. {$updated} record(s) updated.");

        return self::SUCCESS;
    }
}
