<?php

declare(strict_types=1);

namespace Modules\Couriers\Services;

use Modules\Couriers\Contracts\CourierServiceInterface;
use Modules\Couriers\DTOs\CreateOrderDTO;
use Modules\Couriers\DTOs\CreateOrderResultDTO;
use Modules\Couriers\DTOs\TrackingResultDTO;
use Modules\Couriers\DTOs\WebhookPayloadDTO;
use Illuminate\Support\Facades\Cache;

class MockCourierService implements CourierServiceInterface
{
    private const CACHE_KEY = 'mock_courier_orders';

    private const STATUS_FLOW = [
        'PENDING',
        'DISPATCHED',
        'PICKED_UP',
        'IN_TRANSIT',
        'ARRIVED_HUB',
        'OUT_FOR_DELIVERY',
        'DELIVERED',
    ];

    private StatusMapper $statusMapper;

    public function __construct(StatusMapper $statusMapper)
    {
        $this->statusMapper = $statusMapper;
    }

    public function getCode(): string
    {
        return 'MOCK';
    }

    public function createOrder(CreateOrderDTO $dto): CreateOrderResultDTO
    {
        $trackingNumber = 'MOCK'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);

        $order = [
            'tracking_number' => $trackingNumber,
            'status' => 'PENDING',
            'status_index' => 0,
            'receiver_name' => $dto->receiverName,
            'receiver_phone' => $dto->receiverPhone,
            'receiver_address' => $dto->receiverAddress,
            'receiver_city' => $dto->receiverCity,
            'item_name' => $dto->itemName,
            'item_qty' => $dto->itemQty,
            'cod_amount' => $dto->codAmount,
            'weight' => $dto->weight,
            'waybill_id' => $dto->waybillId,
            'created_at' => now()->toIso8601String(),
            'tracking_history' => [
                [
                    'status' => 'PENDING',
                    'location' => 'Mock Warehouse',
                    'description' => 'Order created in mock system',
                    'timestamp' => now()->toIso8601String(),
                ],
            ],
        ];

        $this->storeOrder($trackingNumber, $order);

        return new CreateOrderResultDTO(
            success: true,
            trackingNumber: $trackingNumber,
            sortCode: 'MOCK-SORT-'.substr($trackingNumber, -4),
            rawResponse: ['message' => 'Mock order created', 'pno' => $trackingNumber],
        );
    }

    public function cancelOrder(string $trackingNumber): bool
    {
        $orders = $this->getOrders();

        if (! isset($orders[$trackingNumber])) {
            return false;
        }

        $orders[$trackingNumber]['status'] = 'CANCELLED';
        $orders[$trackingNumber]['tracking_history'][] = [
            'status' => 'CANCELLED',
            'location' => 'Mock System',
            'description' => 'Order cancelled',
            'timestamp' => now()->toIso8601String(),
        ];

        $this->saveOrders($orders);

        return true;
    }

    public function queryTracking(array $waybillNumbers): array
    {
        $results = [];
        $orders = $this->getOrders();

        foreach ($waybillNumbers as $number) {
            $order = $orders[$number] ?? null;

            if (! $order) {
                continue;
            }

            $status = $order['status'];
            $mappedStatus = $this->statusMapper->resolve('MOCK', $status);
            $lastEvent = end($order['tracking_history']);

            $results[] = new TrackingResultDTO(
                waybillNumber: $number,
                mappedStatus: $mappedStatus,
                courierStatus: $status,
                location: $lastEvent['location'] ?? null,
                statusAt: isset($lastEvent['timestamp'])
                    ? new \DateTimeImmutable($lastEvent['timestamp'])
                    : null,
                rawData: $order,
            );
        }

        return $results;
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        return true;
    }

    public function parseWebhookPayload(array $data): WebhookPayloadDTO
    {
        $status = $data['status'] ?? 'PENDING';
        $mappedStatus = $this->statusMapper->resolve('MOCK', $status);

        return new WebhookPayloadDTO(
            waybillNumber: $data['tracking_number'] ?? $data['pno'] ?? '',
            mappedStatus: $mappedStatus,
            courierStatus: $status,
            location: $data['location'] ?? 'Mock Location',
            statusAt: isset($data['timestamp'])
                ? new \DateTimeImmutable($data['timestamp'])
                : null,
            reason: $data['description'] ?? null,
            rawData: $data,
        );
    }

    public function testConnection(): array
    {
        return [
            'connected' => true,
            'message' => 'Mock courier service is always available',
            'status' => 200,
        ];
    }

    public function advanceStatus(string $trackingNumber): ?array
    {
        $orders = $this->getOrders();

        if (! isset($orders[$trackingNumber])) {
            return null;
        }

        $order = $orders[$trackingNumber];
        $currentIndex = $order['status_index'];

        if ($order['status'] === 'CANCELLED' || $order['status'] === 'DELIVERED') {
            return $order;
        }

        $nextIndex = $currentIndex + 1;

        if ($nextIndex >= count(self::STATUS_FLOW)) {
            return $order;
        }

        $nextStatus = self::STATUS_FLOW[$nextIndex];
        $order['status'] = $nextStatus;
        $order['status_index'] = $nextIndex;

        $locations = [
            'DISPATCHED' => 'Mock Dispatch Center',
            'PICKED_UP' => 'Mock Pickup Point',
            'IN_TRANSIT' => 'Mock Transit Hub',
            'ARRIVED_HUB' => 'Mock Destination Hub',
            'OUT_FOR_DELIVERY' => 'Mock Delivery Van',
            'DELIVERED' => 'Mock Delivery Address',
        ];

        $order['tracking_history'][] = [
            'status' => $nextStatus,
            'location' => $locations[$nextStatus] ?? 'Mock Location',
            'description' => "Status advanced to {$nextStatus}",
            'timestamp' => now()->toIso8601String(),
        ];

        $orders[$trackingNumber] = $order;
        $this->saveOrders($orders);

        return $order;
    }

    public function setStatus(string $trackingNumber, string $status): ?array
    {
        $orders = $this->getOrders();

        if (! isset($orders[$trackingNumber])) {
            return null;
        }

        $order = $orders[$trackingNumber];
        $order['status'] = $status;

        $flowIndex = array_search($status, self::STATUS_FLOW);
        $order['status_index'] = $flowIndex !== false ? $flowIndex : count(self::STATUS_FLOW) - 1;

        $order['tracking_history'][] = [
            'status' => $status,
            'location' => 'Mock Manual Update',
            'description' => "Status manually set to {$status}",
            'timestamp' => now()->toIso8601String(),
        ];

        $orders[$trackingNumber] = $order;
        $this->saveOrders($orders);

        return $order;
    }

    public function getOrder(string $trackingNumber): ?array
    {
        $orders = $this->getOrders();

        return $orders[$trackingNumber] ?? null;
    }

    public function getAllOrders(): array
    {
        return $this->getOrders();
    }

    public function reset(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function resetOrder(string $trackingNumber): bool
    {
        $orders = $this->getOrders();

        if (! isset($orders[$trackingNumber])) {
            return false;
        }

        unset($orders[$trackingNumber]);
        $this->saveOrders($orders);

        return true;
    }

    public function generateWebhookPayload(string $trackingNumber): ?array
    {
        $order = $this->getOrder($trackingNumber);

        if (! $order) {
            return null;
        }

        $lastEvent = end($order['tracking_history']);

        return [
            'tracking_number' => $trackingNumber,
            'status' => $order['status'],
            'location' => $lastEvent['location'] ?? 'Mock Location',
            'description' => $lastEvent['description'] ?? '',
            'timestamp' => now()->toIso8601String(),
            'raw_data' => $order,
        ];
    }

    private function getOrders(): array
    {
        return Cache::get(self::CACHE_KEY, []);
    }

    private function saveOrders(array $orders): void
    {
        Cache::put(self::CACHE_KEY, $orders, now()->addDays(7));
    }

    private function storeOrder(string $trackingNumber, array $order): void
    {
        $orders = $this->getOrders();
        $orders[$trackingNumber] = $order;
        $this->saveOrders($orders);
    }
}
