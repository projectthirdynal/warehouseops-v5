<?php

declare(strict_types=1);

namespace Modules\Couriers\Listeners;

use Modules\Couriers\Events\TrackingStatusUpdated;
use Modules\Couriers\Services\CourierStatusSyncService;
use App\Models\SiteSetting;
use Illuminate\Support\Facades\Log;

class SyncOrderFromWaybillStatus
{
    public function __construct(
        private CourierStatusSyncService $syncService,
    ) {}

    public function handle(TrackingStatusUpdated $event): void
    {
        $enabled = SiteSetting::get('courier_status_sync_enabled', '1') === '1';
        if (! $enabled) {
            return;
        }

        $syncIntermediate = SiteSetting::get('courier_status_sync_intermediate', '1') === '1';

        $waybillStatus = $event->payload->mappedStatus;

        if (! $syncIntermediate && ! $waybillStatus->isTerminal()) {
            return;
        }

        try {
            $this->syncService->syncWaybillToOrder($event->waybill);
        } catch (\Throwable $e) {
            Log::error("SyncOrderFromWaybillStatus failed for waybill {$event->waybill->id}: {$e->getMessage()}");
        }
    }
}
