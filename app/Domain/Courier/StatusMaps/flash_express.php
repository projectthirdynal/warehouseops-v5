<?php

use App\Domain\Waybill\Enums\WaybillStatus;

return [
    0 => WaybillStatus::PENDING,          // Order Created
    1 => WaybillStatus::PICKED_UP,        // Picked Up
    2 => WaybillStatus::IN_TRANSIT,       // In Transit
    3 => WaybillStatus::OUT_FOR_DELIVERY, // Out for Delivery
    4 => WaybillStatus::DELIVERED,        // Delivered
    5 => WaybillStatus::DELIVERY_FAILED,  // Failed Delivery
    6 => WaybillStatus::RETURNING,        // Returning
    7 => WaybillStatus::RETURNED,         // Returned
    8 => WaybillStatus::CANCELLED,        // Cancelled
];
