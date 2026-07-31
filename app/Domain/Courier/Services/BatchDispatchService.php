<?php

declare(strict_types=1);

namespace App\Domain\Courier\Services;

use App\Domain\Courier\Actions\CreateCourierOrder;
use App\Domain\Waybill\Enums\WaybillStatus;
use App\Models\Waybill;
use Illuminate\Support\Facades\Log;

class BatchDispatchService
{
    public function __construct(
        private CreateCourierOrder $createCourierOrder,
    ) {}

    /**
     * Dispatch multiple waybills to a courier API.
     *
     * @param array<int> $waybillIds
     * @param string $courierCode
     * @param array $senderDefaults
     * @return array{
     *     total: int,
     *     success: int,
     *     failed: int,
     *     results: array<int, array{
     *         waybill_id: int,
     *         waybill_number: string,
     *         receiver_name: string,
     *         success: bool,
     *         tracking_number: ?string,
     *         error_message: ?string,
     *     }>,
     * }
     */
    public function dispatch(array $waybillIds, string $courierCode, array $senderDefaults = []): array
    {
        $waybills = Waybill::whereIn('id', $waybillIds)
            ->where('status', WaybillStatus::PENDING->value)
            ->get();

        $results = [];
        $successCount = 0;
        $failedCount = 0;

        foreach ($waybills as $waybill) {
            $resultEntry = [
                'waybill_id'      => $waybill->id,
                'waybill_number'  => $waybill->waybill_number,
                'receiver_name'   => $waybill->receiver_name ?? '—',
                'success'         => false,
                'tracking_number' => null,
                'error_message'   => null,
            ];

            try {
                $result = $this->createCourierOrder->execute($waybill, $courierCode, $senderDefaults);

                if ($result->success && $result->trackingNumber) {
                    $resultEntry['success'] = true;
                    $resultEntry['tracking_number'] = $result->trackingNumber;
                    $successCount++;
                } else {
                    $resultEntry['error_message'] = $result->errorMessage ?? 'Unknown error from courier API';
                    $failedCount++;
                }
            } catch (\Throwable $e) {
                $resultEntry['error_message'] = $e->getMessage();
                $failedCount++;
                Log::error("Batch dispatch failed for waybill {$waybill->waybill_number}", [
                    'courier' => $courierCode,
                    'error'   => $e->getMessage(),
                ]);
            }

            // Small delay between API calls to avoid rate limiting
            usleep(200_000);

            $results[] = $resultEntry;
        }

        // Track waybills that were skipped (not PENDING)
        $skippedIds = array_diff($waybillIds, $waybills->pluck('id')->toArray());
        foreach ($skippedIds as $skippedId) {
            $skipped = Waybill::find($skippedId);
            $results[] = [
                'waybill_id'      => $skippedId,
                'waybill_number'  => $skipped?->waybill_number ?? "ID:{$skippedId}",
                'receiver_name'   => $skipped?->receiver_name ?? '—',
                'success'         => false,
                'tracking_number' => null,
                'error_message'   => 'Skipped: waybill is not in PENDING status',
            ];
            $failedCount++;
        }

        return [
            'total'   => count($waybillIds),
            'success' => $successCount,
            'failed'  => $failedCount,
            'results' => $results,
        ];
    }

    /**
     * Get stats for batch dispatch dashboard.
     */
    public function stats(): array
    {
        $pending = Waybill::where('status', WaybillStatus::PENDING->value);

        return [
            'pending_count'     => $pending->count(),
            'pending_by_courier' => $pending->clone()
                ->selectRaw('COALESCE(courier_provider, ?) as provider, COUNT(*) as count', ['UNASSIGNED'])
                ->groupBy('provider')
                ->pluck('count', 'provider')
                ->toArray(),
            'dispatched_today' => Waybill::where('status', WaybillStatus::DISPATCHED->value)
                ->whereDate('dispatched_at', today())
                ->count(),
            'total_dispatched' => Waybill::where('status', WaybillStatus::DISPATCHED->value)->count(),
        ];
    }
}
