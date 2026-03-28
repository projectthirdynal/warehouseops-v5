<?php

declare(strict_types=1);

namespace App\Domain\Courier\DTOs;

use App\Models\Waybill;

final readonly class CreateOrderDTO
{
    public function __construct(
        public string  $senderName,
        public string  $senderPhone,
        public string  $senderAddress,
        public string  $senderCity,
        public string  $senderProvince,
        public string  $receiverName,
        public string  $receiverPhone,
        public string  $receiverAddress,
        public string  $receiverCity,
        public string  $receiverProvince,
        public string  $receiverBarangay,
        public ?string $postalCode,
        public string  $itemName,
        public int     $itemQty,
        public float   $itemValue,
        public float   $codAmount,
        public float   $weight,
        public ?string $remarks = null,
        public ?int    $waybillId = null,
    ) {}

    public static function fromWaybill(Waybill $waybill, array $senderDefaults = []): self
    {
        return new self(
            senderName:      $senderDefaults['name'] ?? config('app.name', 'WarehouseOps'),
            senderPhone:     $senderDefaults['phone'] ?? '',
            senderAddress:   $senderDefaults['address'] ?? '',
            senderCity:      $senderDefaults['city'] ?? '',
            senderProvince:  $senderDefaults['province'] ?? '',
            receiverName:    $waybill->receiver_name,
            receiverPhone:   $waybill->receiver_phone,
            receiverAddress: $waybill->receiver_address,
            receiverCity:    $waybill->city ?? '',
            receiverProvince: $waybill->state ?? '',
            receiverBarangay: $waybill->barangay ?? '',
            postalCode:      $waybill->postal_code,
            itemName:        $waybill->item_name ?? 'Package',
            itemQty:         $waybill->item_qty ?? 1,
            itemValue:       (float) ($waybill->amount ?? 0),
            codAmount:       (float) ($waybill->cod_amount ?? $waybill->amount ?? 0),
            weight:          (float) ($waybill->settlement_weight ?? 0.5),
            remarks:         $waybill->remarks,
            waybillId:       $waybill->id,
        );
    }
}
