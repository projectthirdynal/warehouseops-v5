<?php

declare(strict_types=1);

namespace App\Domain\Courier\Listeners;

use App\Domain\Courier\Events\TrackingStatusUpdated;
use App\Domain\Waybill\Enums\WaybillStatus;
use App\Services\SmsSequenceService;

class TriggerSmsOnStatusChange
{
    public function __construct(
        private SmsSequenceService $sequenceService,
    ) {}

    public function handle(TrackingStatusUpdated $event): void
    {
        $eventMap = [
            WaybillStatus::DISPATCHED->value        => 'waybill_dispatched',
            WaybillStatus::OUT_FOR_DELIVERY->value   => 'waybill_out_for_delivery',
            WaybillStatus::DELIVERED->value           => 'waybill_delivered',
            WaybillStatus::RETURNED->value            => 'waybill_returned',
        ];

        $trigger = $eventMap[$event->payload->mappedStatus->value] ?? null;

        if ($trigger) {
            $this->sequenceService->trigger($trigger, $event->waybill);
        }
    }
}
