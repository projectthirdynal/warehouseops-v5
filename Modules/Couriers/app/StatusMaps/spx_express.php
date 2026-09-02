<?php

use Modules\Waybills\Enums\WaybillStatus;

return [
    // SPX Express (Shopee Express) status labels from exports and Google Sheets
    'Pending' => WaybillStatus::PENDING,
    'pending' => WaybillStatus::PENDING,
    'PENDING' => WaybillStatus::PENDING,
    'Pickup' => WaybillStatus::PICKED_UP,
    'Picked Up' => WaybillStatus::PICKED_UP,
    'PICKED_UP' => WaybillStatus::PICKED_UP,
    'In Transit' => WaybillStatus::IN_TRANSIT,
    'IN_TRANSIT' => WaybillStatus::IN_TRANSIT,
    'Arrived at Hub' => WaybillStatus::ARRIVED_HUB,
    'Arrived Hub' => WaybillStatus::ARRIVED_HUB,
    'At Sorting Center' => WaybillStatus::ARRIVED_HUB,
    'At Warehouse' => WaybillStatus::ARRIVED_HUB,
    'Out for Delivery' => WaybillStatus::OUT_FOR_DELIVERY,
    'OUT_FOR_DELIVERY' => WaybillStatus::OUT_FOR_DELIVERY,
    'Delivering' => WaybillStatus::OUT_FOR_DELIVERY,
    'Attempt Delivery' => WaybillStatus::OUT_FOR_DELIVERY,
    'Delivered' => WaybillStatus::DELIVERED,
    'DELIVERED' => WaybillStatus::DELIVERED,
    'Signed' => WaybillStatus::DELIVERED,
    'Delivery Failed' => WaybillStatus::DELIVERY_FAILED,
    'FAILED' => WaybillStatus::DELIVERY_FAILED,
    'Failed Delivery' => WaybillStatus::DELIVERY_FAILED,
    'Return to Sender' => WaybillStatus::RETURNING,
    'RTS' => WaybillStatus::RETURNING,
    'Returning' => WaybillStatus::RETURNING,
    'RETURNING' => WaybillStatus::RETURNING,
    'Returned' => WaybillStatus::RETURNED,
    'RETURNED' => WaybillStatus::RETURNED,
    'Cancelled' => WaybillStatus::CANCELLED,
    'CANCELLED' => WaybillStatus::CANCELLED,
    'Cancelled by Buyer' => WaybillStatus::CANCELLED,
    'Lost' => WaybillStatus::DELIVERY_FAILED,
];
