<?php

use App\Domain\Waybill\Enums\WaybillStatus;

return [
    // scanType values from J&T Open API webhooks/tracking
    'PICKUP'      => WaybillStatus::PICKED_UP,
    'SEND'        => WaybillStatus::DISPATCHED,
    'ARRIVAL'     => WaybillStatus::ARRIVED_HUB,
    'DEPARTURE'   => WaybillStatus::IN_TRANSIT,
    'DELIVERY'    => WaybillStatus::OUT_FOR_DELIVERY,
    'SIGNED'      => WaybillStatus::DELIVERED,
    'FAILED'      => WaybillStatus::DELIVERY_FAILED,
    'RETURN'      => WaybillStatus::RETURNING,
    'RETURNED'    => WaybillStatus::RETURNED,
    'CANCEL'      => WaybillStatus::CANCELLED,
    'PROBLEMATIC' => WaybillStatus::DELIVERY_FAILED,

    // Human-readable labels from Excel imports (legacy mapping)
    'Picked Up'        => WaybillStatus::PICKED_UP,
    'In Transit'       => WaybillStatus::IN_TRANSIT,
    'Arrived Hub'      => WaybillStatus::ARRIVED_HUB,
    'At Warehouse'     => WaybillStatus::ARRIVED_HUB,
    'Out for Delivery' => WaybillStatus::OUT_FOR_DELIVERY,
    'Delivering'       => WaybillStatus::OUT_FOR_DELIVERY,
    'Delivered'        => WaybillStatus::DELIVERED,
    'Return to Sender' => WaybillStatus::RETURNING,
    'RTS'              => WaybillStatus::RETURNING,
    'Returned'         => WaybillStatus::RETURNED,
    'Pending'          => WaybillStatus::PENDING,
    'Dispatched'       => WaybillStatus::DISPATCHED,
    'Cancelled'        => WaybillStatus::CANCELLED,
];
