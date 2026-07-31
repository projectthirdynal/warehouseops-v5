<?php

declare(strict_types=1);

namespace App\Domain\Courier\Listeners;

use App\Domain\Courier\Events\TrackingStatusUpdated;
use App\Domain\Courier\Services\CourierStatusSyncService;
use App\Models\SiteSetting;

class SyncOrderFromWaybillStatus
{
    public function __construct(
        private CourierStatusSyncService $syncService,
    ) {}

    public function handle(TrackingStatusUpdated $event): void
    {
        $enabled = SiteSetting::get('courier_status_sync_enabled', '1') === '1';
        if (!$enabled) {
            return;
        }

        $syncIntermediate = SiteSetting::get('courier_status_sync_intermediate', '1') === '1';

        $waybillStatus = $event->payload->mappedStatus;

        if (!$syncIntermediate && !$waybillStatus->isTerminal()) {
            return;
        }

        try {
            $this->syncService->syncWaybillToOrder($event->waybill);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("SyncOrderFromWaybillStatus failed for waybill {$event->waybill->id}: {$e->getMessage()}");
        }
    }
}
