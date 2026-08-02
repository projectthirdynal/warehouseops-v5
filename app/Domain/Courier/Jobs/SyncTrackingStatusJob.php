<?php

declare(strict_types=1);

namespace App\Domain\Courier\Jobs;

use App\Domain\Courier\DTOs\TrackingResultDTO;
use App\Domain\Courier\DTOs\WebhookPayloadDTO;
use App\Domain\Courier\Events\TrackingStatusUpdated;
use App\Domain\Courier\Services\CourierServiceManager;
use App\Domain\Courier\Services\CourierStatusSyncService;
use App\Domain\Waybill\Enums\WaybillStatus;
use App\Models\SiteSetting;
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

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        private ?string $courierCode = null,
        private string $trigger = 'scheduled',
    ) {}

    public function handle(CourierServiceManager $manager, CourierStatusSyncService $syncService): void
    {
        if (empty(config('services.couriers.jnt.api_key')) && empty(config('services.couriers.flash.api_key'))) {
            Log::warning('SyncTrackingStatusJob: no courier API keys configured, skipping.');

            return;
        }

        $startTime = microtime(true);
        $runId = str()->uuid()->toString();
        $lookbackDays = (int) SiteSetting::get('courier_sync_lookback_days', '21');
        $maxWaybills = (int) SiteSetting::get('courier_sync_max_waybills', '500');

        // Skip terminal statuses (DELIVERED, RETURNED, CANCELLED) and PENDING (not yet dispatched).
        $skipStatuses = [
            WaybillStatus::DELIVERED->value,
            WaybillStatus::RETURNED->value,
            WaybillStatus::CANCELLED->value,
            WaybillStatus::PENDING->value,
        ];

        $query = Waybill::query()
            ->whereNotIn('status', $skipStatuses)
            ->where('courier_provider', '!=', 'MANUAL')
            ->whereNotNull('waybill_number')
            ->where('submitted_at', '>=', now()->subDays($lookbackDays));

        if ($this->courierCode) {
            $query->where('courier_provider', $this->courierCode);
        }

        $totalChecked = 0;
        $totalUpdated = 0;
        $totalUnchanged = 0;
        $allErrors = [];
        $perCourierStats = [];

        $query->select('id', 'waybill_number', 'courier_provider', 'status')
            ->chunkById(60, function ($waybills) use ($manager, &$totalChecked, &$totalUpdated, &$totalUnchanged, &$allErrors, &$perCourierStats) {
                $grouped = $waybills->groupBy('courier_provider');

                foreach ($grouped as $code => $batch) {
                    $batchChecked = $batch->count();
                    $batchUpdated = 0;
                    $batchUnchanged = 0;

                    try {
                        $service = $manager->driver($code);
                        $numbers = $batch->pluck('waybill_number')->toArray();

                        $results = $service->queryTracking($numbers);

                        foreach ($results as $result) {
                            $wasUpdated = $this->processTrackingResult($result, $batch);
                            if ($wasUpdated) {
                                $batchUpdated++;
                            } else {
                                $batchUnchanged++;
                            }
                        }
                    } catch (\Exception $e) {
                        Log::error("Tracking sync failed for {$code}", [
                            'error' => $e->getMessage(),
                            'batch_size' => $batch->count(),
                        ]);
                        $allErrors[] = "{$code}: {$e->getMessage()}";
                        $batchUnchanged = $batchChecked;
                    }

                    $totalChecked += $batchChecked;
                    $totalUpdated += $batchUpdated;
                    $totalUnchanged += $batchUnchanged;

                    if (! isset($perCourierStats[$code])) {
                        $perCourierStats[$code] = ['checked' => 0, 'updated' => 0, 'unchanged' => 0];
                    }
                    $perCourierStats[$code]['checked'] += $batchChecked;
                    $perCourierStats[$code]['updated'] += $batchUpdated;
                    $perCourierStats[$code]['unchanged'] += $batchUnchanged;
                }
            });

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        $syncService->logSyncRun([
            'run_id' => $runId,
            'courier_code' => $this->courierCode,
            'trigger' => $this->trigger,
            'waybills_checked' => $totalChecked,
            'waybills_updated' => $totalUpdated,
            'waybills_unchanged' => $totalUnchanged,
            'errors_count' => count($allErrors),
            'errors' => $allErrors ?: null,
            'per_courier' => $perCourierStats ?: null,
            'duration_ms' => $durationMs,
            'status' => count($allErrors) > 0 ? 'completed_with_errors' : 'completed',
        ]);

        Log::info("SyncTrackingStatusJob completed: {$totalChecked} checked, {$totalUpdated} updated, {$totalUnchanged} unchanged, {$durationMs}ms");
    }

    private function processTrackingResult(TrackingResultDTO $result, $waybills): bool
    {
        $waybill = $waybills->firstWhere('waybill_number', $result->waybillNumber);
        if (! $waybill) {
            return false;
        }

        $currentStatus = WaybillStatus::tryFrom($waybill->status);

        // Skip if status hasn't changed or current is terminal
        if ($currentStatus === $result->mappedStatus) {
            return false;
        }
        if ($currentStatus?->isTerminal()) {
            return false;
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

        // Update waybill's last-known location
        $domainWaybill->update([
            'last_location_description' => $result->location,
            'last_location_at' => now(),
        ]);

        // Fire event for SMS triggers
        $payload = new WebhookPayloadDTO(
            waybillNumber: $result->waybillNumber,
            mappedStatus: $result->mappedStatus,
            courierStatus: $result->courierStatus,
            location: $result->location,
            statusAt: $result->statusAt,
            rawData: $result->rawData,
        );

        event(new TrackingStatusUpdated($waybill->fresh(), $payload));

        return true;
    }
}
