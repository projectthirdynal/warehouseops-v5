<?php

declare(strict_types=1);

namespace App\Domain\Courier\Jobs;

use App\Domain\Courier\DTOs\TrackingResultDTO;
use App\Domain\Courier\DTOs\WebhookPayloadDTO;
use App\Domain\Courier\Events\TrackingStatusUpdated;
use App\Domain\Courier\Services\CourierServiceManager;
use App\Domain\Waybill\Enums\WaybillStatus;
use App\Models\Waybill;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncTrackingStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 3;

    public function __construct(
        private ?string $courierCode = null
    ) {}

    public function handle(CourierServiceManager $manager): void
    {
        $terminalStatuses = [
            WaybillStatus::DELIVERED->value,
            WaybillStatus::RETURNED->value,
            WaybillStatus::CANCELLED->value,
        ];

        $query = Waybill::query()
            ->whereNotIn('status', $terminalStatuses)
            ->where('courier_provider', '!=', 'MANUAL')
            ->whereNotNull('waybill_number');

        if ($this->courierCode) {
            $query->where('courier_provider', $this->courierCode);
        }

        $query->select('id', 'waybill_number', 'courier_provider', 'status')
            ->chunkById(200, function ($waybills) use ($manager) {
                $grouped = $waybills->groupBy('courier_provider');

                foreach ($grouped as $code => $batch) {
                    try {
                        $service = $manager->driver($code);
                        $numbers = $batch->pluck('waybill_number')->toArray();

                        $results = $service->queryTracking($numbers);

                        foreach ($results as $result) {
                            $this->processTrackingResult($result, $batch);
                        }
                    } catch (\Exception $e) {
                        Log::error("Tracking sync failed for {$code}", [
                            'error'      => $e->getMessage(),
                            'batch_size' => $batch->count(),
                        ]);
                    }
                }
            });
    }

    private function processTrackingResult(TrackingResultDTO $result, $waybills): void
    {
        $waybill = $waybills->firstWhere('waybill_number', $result->waybillNumber);
        if (!$waybill) {
            return;
        }

        $currentStatus = WaybillStatus::tryFrom($waybill->status);

        // Skip if status hasn't changed or current is terminal
        if ($currentStatus === $result->mappedStatus) {
            return;
        }
        if ($currentStatus?->isTerminal()) {
            return;
        }

        // Use domain model for consistent tracking history creation
        $domainWaybill = \App\Domain\Waybill\Models\Waybill::find($waybill->id);
        $domainWaybill->updateStatus($result->mappedStatus);

        // Append location + raw_data to tracking history
        $latestHistory = $domainWaybill->trackingHistory()->latest('tracked_at')->first();
        if ($latestHistory) {
            $latestHistory->update([
                'location' => $result->location,
                'raw_data' => $result->rawData,
            ]);
        }

        // Fire event for SMS triggers
        $payload = new WebhookPayloadDTO(
            waybillNumber: $result->waybillNumber,
            mappedStatus:  $result->mappedStatus,
            courierStatus: $result->courierStatus,
            location:      $result->location,
            statusAt:      $result->statusAt,
            rawData:       $result->rawData,
        );

        event(new TrackingStatusUpdated($waybill->fresh(), $payload));
    }
}
