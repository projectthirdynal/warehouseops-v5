<?php

declare(strict_types=1);

namespace App\Domain\Shop\Services;

use App\Domain\Order\Models\Order;

class AddressFormatService
{
    public function formatForCourier(Order $order, string $courierCode = 'GENERIC'): array
    {
        $courier = strtoupper($courierCode);

        return match ($courier) {
            'FLASH' => $this->formatForFlash($order),
            'JNT'   => $this->formatForJnt($order),
            default => $this->formatGeneric($order),
        };
    }

    private function formatForFlash(Order $order): array
    {
        $address = $order->receiver_address ?? '';
        $barangay = $order->barangay ?? '';
        $city = $order->city ?? '';
        $province = $order->state ?? '';
        $postalCode = $order->postal_code ?? '';

        $fields = [
            'dstDetailAddress'  => $address,
            'dstDistrictName'   => $barangay,
            'dstCityName'       => $city,
            'dstProvinceName'   => $province,
            'dstPostalCode'     => $postalCode,
        ];

        $combined = trim(implode(', ', array_filter([$address, $barangay, $city, $province . ($postalCode ? ' ' . $postalCode : '')])));

        return [
            'formatted' => $combined,
            'fields'    => $fields,
            'courier'   => 'FLASH',
            'notes'     => [
                'FLASH requires barangay as dstDistrictName',
                'Postal code is included with province in the combined string',
            ],
        ];
    }

    private function formatForJnt(Order $order): array
    {
        $address = $order->receiver_address ?? '';
        $barangay = $order->barangay ?? '';
        $city = $order->city ?? '';
        $province = $order->state ?? '';

        $receiverAddress = trim(implode(', ', array_filter([$address, $barangay])));

        $fields = [
            'receiverAddress'  => $receiverAddress,
            'receiverProvince' => $province,
            'receiverCity'     => $city,
        ];

        $combined = trim(implode(', ', array_filter([$receiverAddress, $city, $province])));

        return [
            'formatted' => $combined,
            'fields'    => $fields,
            'courier'   => 'J&T',
            'notes'     => [
                'J&T combines barangay into the address field',
                'Province and city are separate fields',
            ],
        ];
    }

    private function formatGeneric(Order $order): array
    {
        $address = $order->receiver_address ?? '';
        $barangay = $order->barangay ?? '';
        $city = $order->city ?? '';
        $province = $order->state ?? '';
        $postalCode = $order->postal_code ?? '';

        $fields = [
            'address'    => $address,
            'barangay'   => $barangay,
            'city'       => $city,
            'province'   => $province,
            'postalCode' => $postalCode,
        ];

        $combined = trim(implode(', ', array_filter([$address, $barangay, $city, $province . ($postalCode ? ' ' . $postalCode : '')])));

        return [
            'formatted' => $combined,
            'fields'    => $fields,
            'courier'   => 'Generic',
            'notes'     => [],
        ];
    }
}
