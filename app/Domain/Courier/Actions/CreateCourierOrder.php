<?php

declare(strict_types=1);

namespace App\Domain\Courier\Actions;

use App\Domain\Courier\DTOs\CreateOrderDTO;
use App\Domain\Courier\DTOs\CreateOrderResultDTO;
use App\Domain\Courier\Services\CourierServiceManager;
use App\Domain\Waybill\Enums\WaybillStatus;
use App\Models\Waybill;
use Illuminate\Support\Facades\Log;

class CreateCourierOrder
{
    public function __construct(
        private CourierServiceManager $manager,
    ) {}

    /**
     * Submit a waybill to a courier API for shipment.
     */
    public function execute(Waybill $waybill, string $courierCode, array $senderDefaults = []): CreateOrderResultDTO
    {
        $service = $this->manager->driver($courierCode);
        $dto = CreateOrderDTO::fromWaybill($waybill, $senderDefaults);

        $result = $service->createOrder($dto);

        if ($result->success && $result->trackingNumber) {
            $waybill->update([
                'waybill_number'  => $result->trackingNumber,
                'courier_provider' => $courierCode,
                'status'          => WaybillStatus::DISPATCHED->value,
                'dispatched_at'   => now(),
            ]);

            // Create tracking history entry
            $waybill->trackingHistory()->create([
                'status'          => WaybillStatus::DISPATCHED->value,
                'previous_status' => WaybillStatus::PENDING->value,
                'reason'          => "Order created via {$service->getCode()} API",
                'raw_data'        => $result->rawResponse,
                'tracked_at'      => now(),
            ]);

            Log::info("Courier order created", [
                'courier'  => $courierCode,
                'tracking' => $result->trackingNumber,
                'waybill'  => $waybill->id,
            ]);
        }

        return $result;
    }
}
