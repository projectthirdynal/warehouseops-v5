<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Inventory\Services\ReorderPointAlertService;
use Illuminate\Console\Command;

class CheckReorderPoints extends Command
{
    protected $signature = 'inventory:check-reorder-points';

    protected $description = 'Scan all stock for items at or below reorder point, create alerts, and send notifications.';

    public function handle(ReorderPointAlertService $service): int
    {
        $this->info('Scanning stock for reorder point breaches...');

        $result = $service->scanAndNotify();

        $this->info("Created: {$result['created']} new alerts");
        $this->info("Resolved: {$result['resolved']} stale alerts");
        $this->info("Notified: {$result['notified']} recipients");

        return self::SUCCESS;
    }
}
