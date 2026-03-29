<?php

use App\Domain\Waybill\Enums\WaybillStatus;

/**
 * Flash Express PH official parcel state codes.
 * Source: open-docs.flashexpress.com/ph — Section 8, "Parcel State Codes"
 */
return [
    1 => WaybillStatus::PENDING,          // Order created / Pending pickup
    2 => WaybillStatus::OUT_FOR_DELIVERY, // Out for delivery
    3 => WaybillStatus::IN_TRANSIT,       // In transit at hub/warehouse
    4 => WaybillStatus::ARRIVED_HUB,      // At delivery branch
    5 => WaybillStatus::DELIVERED,        // Delivered / Signed
    6 => WaybillStatus::DELIVERY_FAILED,  // Failed delivery attempt
    7 => WaybillStatus::RETURNED,         // Returned
    8 => WaybillStatus::CANCELLED,        // Cancelled
];
