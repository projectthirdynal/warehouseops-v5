<?php

declare(strict_types=1);

namespace App\Domain\Courier\Http\Controllers;

use App\Domain\Courier\Events\TrackingStatusUpdated;
use App\Domain\Courier\Models\CourierApiLog;
use App\Domain\Courier\Models\CourierProvider;
use App\Domain\Courier\Services\CourierServiceManager;
use App\Domain\Waybill\Services\DeliveryProofService;
use App\Models\Waybill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CourierWebhookController
{
    public function handle(Request $request, string $courier): JsonResponse
    {
        $manager = app(CourierServiceManager::class);
        $service = $manager->driver($courier);

        // Log the inbound webhook
        $providerId = CourierProvider::where('code', strtoupper($courier))->value('id');
        CourierApiLog::create([
            'courier_provider_id' => $providerId,
            'courier_code'        => strtoupper($courier),
            'action'              => 'webhook',
            'direction'           => 'inbound',
            'request_data'        => $request->all(),
            'is_success'          => true,
        ]);

        $payload = $service->parseWebhookPayload($request->all());

        $waybill = Waybill::where('waybill_number', $payload->waybillNumber)->first();

        if (!$waybill) {
            Log::warning('Webhook received for unknown waybill', [
                'courier' => $courier,
                'number'  => $payload->waybillNumber,
            ]);
            // ACK anyway to stop courier retries
            return $this->ack($courier);
        }

        // Only update if status actually changed and current status is not terminal
        $currentStatus = $waybill->status;
        $isTerminal = in_array($currentStatus, ['DELIVERED', 'RETURNED', 'CANCELLED']);

        if (!$isTerminal && $currentStatus !== $payload->mappedStatus->value) {
            // Use domain model for status update (creates tracking history)
            $domainWaybill = \App\Domain\Waybill\Models\Waybill::find($waybill->id);
            $domainWaybill->updateStatus($payload->mappedStatus, $payload->reason);

            // Append location + raw_data to the tracking history entry
            $latestHistory = $domainWaybill->trackingHistory()->latest('tracked_at')->first();
            if ($latestHistory) {
                $latestHistory->update([
                    'location' => $payload->location,
                    'raw_data' => $payload->rawData,
                ]);
            }

            // Update waybill's last-known location
            $domainWaybill->update([
                'last_location_description' => $payload->location,
                'last_location_at'          => now(),
            ]);

            // Fire event for SMS triggers and other listeners
            event(new TrackingStatusUpdated($waybill->fresh(), $payload));
        }

        // Extract and store delivery proofs (photos/signatures) from webhook payload
        $proofService = app(DeliveryProofService::class);
        $proofs = $proofService->extractProofsFromWebhook($request->all());
        if (!empty($proofs)) {
            $domainWaybill = $domainWaybill ?? \App\Domain\Waybill\Models\Waybill::find($waybill->id);
            foreach ($proofs as $proofData) {
                $proofService->storeFromCourierCallback($domainWaybill, strtoupper($courier), $proofData);
            }
            Log::info('Delivery proofs stored from webhook', [
                'courier'  => $courier,
                'waybill'  => $waybill->waybill_number,
                'count'    => count($proofs),
            ]);
        }

        return $this->ack($courier);
    }

    /**
     * Return the ACK response format each courier expects.
     * Flash requires {"code":1,"message":"success"}, J&T requires {"code":"1","msg":"success"}.
     */
    private function ack(string $courier): JsonResponse
    {
        if (strtoupper($courier) === 'FLASH') {
            return response()->json(['code' => 1, 'message' => 'success']);
        }

        return response()->json(['code' => '1', 'msg' => 'success']);
    }
}
