<?php

use App\Domain\Waybill\Enums\WaybillStatus;

/**
 * Mock courier status map — maps internal status strings directly.
 */
return [
    'PENDING'           => WaybillStatus::PENDING,
    'DISPATCHED'        => WaybillStatus::DISPATCHED,
    'PICKED_UP'         => WaybillStatus::PICKED_UP,
    'IN_TRANSIT'        => WaybillStatus::IN_TRANSIT,
    'ARRIVED_HUB'       => WaybillStatus::ARRIVED_HUB,
    'OUT_FOR_DELIVERY'  => WaybillStatus::OUT_FOR_DELIVERY,
    'DELIVERY_FAILED'   => WaybillStatus::DELIVERY_FAILED,
    'DELIVERED'         => WaybillStatus::DELIVERED,
    'RETURNING'         => WaybillStatus::RETURNING,
    'RETURNED'          => WaybillStatus::RETURNED,
    'CANCELLED'         => WaybillStatus::CANCELLED,
];
