<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Courier\Jobs\SyncTrackingStatusJob;
use App\Domain\Courier\Services\CourierServiceManager;
use App\Domain\Courier\Services\CourierStatusSyncService;
use Illuminate\Console\Command;

class SyncCourierTracking extends Command
{
    protected $signature = 'courier:sync-tracking
                            {--courier= : Specific courier code (FLASH, JNT)}
                            {--trigger=manual : Trigger source (manual, scheduled, api)}';

    protected $description = 'Sync courier tracking statuses from all configured courier APIs';

    public function handle(): int
    {
        $courier = $this->option('courier');
        $trigger = $this->option('trigger');

        if ($courier) {
            $this->info("Starting courier tracking sync for {$courier} (trigger: {$trigger})...");
        } else {
            $this->info("Starting courier tracking sync for all couriers (trigger: {$trigger})...");
        }

        $job = new SyncTrackingStatusJob($courier ? strtoupper($courier) : null, $trigger);
        $job->handle(
            app(CourierServiceManager::class),
            app(CourierStatusSyncService::class),
        );

        $this->info('Courier tracking sync completed.');

        return self::SUCCESS;
    }
}
