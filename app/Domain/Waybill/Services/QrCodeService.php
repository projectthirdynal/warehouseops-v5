<?php

declare(strict_types=1);

namespace App\Domain\Waybill\Services;

use App\Models\Waybill;

class QrCodeService
{
    public function getQrData(Waybill $waybill): array
    {
        $trackingUrl = $this->buildTrackingUrl($waybill);
        $qrContent = $this->buildQrContent($waybill, $trackingUrl);

        return [
            'waybill_number' => $waybill->waybill_number,
            'qr_content' => $qrContent,
            'tracking_url' => $trackingUrl,
            'destination' => [
                'receiver_name' => $waybill->receiver_name,
                'receiver_phone' => $waybill->receiver_phone,
                'receiver_address' => $waybill->receiver_address,
                'barangay' => $waybill->barangay,
                'city' => $waybill->city,
                'state' => $waybill->state,
                'postal_code' => $waybill->postal_code ?? null,
            ],
            'shipment' => [
                'courier_provider' => $waybill->courier_provider,
                'item_name' => $waybill->item_name,
                'item_qty' => $waybill->item_qty,
                'cod_amount' => (float) $waybill->cod_amount,
                'shipping_cost' => (float) $waybill->shipping_cost,
                'status' => $waybill->status,
                'express_type' => $waybill->express_type ?? null,
            ],
            'created_at' => $waybill->created_at?->toIso8601String(),
        ];
    }

    public function buildQrContent(Waybill $waybill, ?string $trackingUrl = null): string
    {
        $trackingUrl = $trackingUrl ?? $this->buildTrackingUrl($waybill);

        $parts = [
            'WB:'.$waybill->waybill_number,
            'R:'.$waybill->receiver_name,
            'P:'.$waybill->receiver_phone,
            'A:'.implode(', ', array_filter([
                $waybill->receiver_address,
                $waybill->barangay,
                $waybill->city,
                $waybill->state,
            ])),
            'C:'.$waybill->courier_provider,
        ];

        if ($waybill->cod_amount && $waybill->cod_amount > 0) {
            $parts[] = 'COD:'.number_format((float) $waybill->cod_amount, 2);
        }

        if ($waybill->item_name) {
            $parts[] = 'I:'.$waybill->item_name.' x'.($waybill->item_qty ?? 1);
        }

        $parts[] = 'U:'.$trackingUrl;

        return implode('|', $parts);
    }

    public function buildTrackingUrl(Waybill $waybill): string
    {
        $courier = strtoupper($waybill->courier_provider ?? '');

        return match ($courier) {
            'JNT', 'J&T' => 'https://www.jtexpress.ph/index/query/index?billcode='.urlencode($waybill->waybill_number),
            'FLASH', 'FLASH_EXPRESS' => 'https://www.flashexpress.ph/fle/tracking?se='.urlencode($waybill->waybill_number),
            default => url("/waybills/{$waybill->id}"),
        };
    }

    public function getLabelData(Waybill $waybill): array
    {
        $data = $this->getQrData($waybill);

        return [
            ...$data,
            'full_address' => implode(', ', array_filter([
                $waybill->receiver_address,
                $waybill->barangay,
                $waybill->city,
                $waybill->state,
                $waybill->postal_code ?? null,
            ])),
            'company' => config('app.name', 'WarehouseOps'),
            'generated_at' => now()->toDateTimeString(),
        ];
    }
}
