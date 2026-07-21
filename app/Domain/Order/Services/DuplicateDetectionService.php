<?php

declare(strict_types=1);

namespace App\Domain\Order\Services;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Shop\Models\ShopOrderItem;
use App\Domain\Shop\Services\PhoneDetectionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class DuplicateDetectionService
{
    private int $defaultTimeWindowHours = 72;

    private array $excludedStatuses = [
        OrderStatus::DRAFT,
        OrderStatus::CANCELLED,
    ];

    public function __construct(
        private readonly PhoneDetectionService $phones,
    ) {}

    /**
     * Detect duplicate orders by customer phone + product within a time window.
     *
     * @param string      $phone      Receiver phone (raw or normalized)
     * @param array<int>  $productIds Product IDs to check against
     * @param int|null    $timeWindowHours  Override the default time window
     * @param int|null    $excludeOrderId   Order ID to exclude (e.g., the order being edited)
     * @return array<string, mixed>
     */
    public function detectDuplicateOrders(
        string $phone,
        array $productIds,
        ?int $timeWindowHours = null,
        ?int $excludeOrderId = null,
    ): array {
        $normalizedPhone = $this->phones->normalize($phone);
        $windowHours = $timeWindowHours ?? $this->defaultTimeWindowHours;
        $cutoff = Carbon::now()->subHours($windowHours);

        if (empty($productIds)) {
            return $this->emptyResult($normalizedPhone, $windowHours);
        }

        $query = Order::query()
            ->with(['product:id,name,sku', 'shopItems:id,order_id,product_id,product_name,quantity,line_total'])
            ->where('receiver_phone', $normalizedPhone)
            ->where('created_at', '>=', $cutoff)
            ->whereNotIn('status', array_map(fn ($s) => $s->value, $this->excludedStatuses));

        if ($excludeOrderId) {
            $query->where('id', '!=', $excludeOrderId);
        }

        $orders = $query->get();

        $duplicates = $orders->filter(function (Order $order) use ($productIds) {
            if (in_array($order->product_id, $productIds, true)) {
                return true;
            }

            return $order->shopItems->contains(fn (ShopOrderItem $item) => in_array($item->product_id, $productIds, true));
        });

        $duplicateWarnings = $duplicates->map(function (Order $order) use ($normalizedPhone) {
            $matchedProducts = $order->shopItems
                ->filter(fn (ShopOrderItem $item) => $item->product_id !== null)
                ->map(fn (ShopOrderItem $item) => [
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'quantity' => $item->quantity,
                ])
                ->values();

            if ($order->product_id && $matchedProducts->isEmpty()) {
                $matchedProducts = collect([[
                    'product_id' => $order->product_id,
                    'product_name' => $order->product?->name ?? 'Unknown',
                    'quantity' => $order->quantity,
                ]]);
            }

            return [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status->value,
                'receiver_name' => $order->receiver_name,
                'receiver_phone' => $order->receiver_phone,
                'total_amount' => (float) $order->total_amount,
                'created_at' => $order->created_at?->toIso8601String(),
                'created_at_formatted' => $order->created_at?->format('M j, Y g:i A'),
                'hours_ago' => $order->created_at?->diffInHours(now()) ?? 0,
                'matched_products' => $matchedProducts->toArray(),
                'courier_code' => $order->courier_code,
            ];
        })->values();

        $isDuplicate = $duplicateWarnings->isNotEmpty();

        return [
            'is_duplicate' => $isDuplicate,
            'phone' => $normalizedPhone,
            'product_ids' => $productIds,
            'time_window_hours' => $windowHours,
            'duplicate_count' => $duplicateWarnings->count(),
            'duplicates' => $duplicateWarnings->toArray(),
            'severity' => $this->determineSeverity($duplicateWarnings->count(), $windowHours),
        ];
    }

    /**
     * Check for any recent orders by phone (broader check for the create-order page).
     *
     * @param string      $phone
     * @param int|null    $days  How many days back to look (default 30)
     * @return array<string, mixed>
     */
    public function checkRecentOrdersByPhone(string $phone, ?int $days = 30): array
    {
        $normalizedPhone = $this->phones->normalize($phone);
        $cutoff = Carbon::now()->subDays($days);

        $orders = Order::query()
            ->with('product:id,name,sku')
            ->where('receiver_phone', $normalizedPhone)
            ->where('created_at', '>=', $cutoff)
            ->whereNotIn('status', [OrderStatus::DRAFT->value, OrderStatus::CANCELLED->value])
            ->latest()
            ->limit(10)
            ->get(['id', 'order_number', 'product_id', 'status', 'total_amount', 'created_at', 'receiver_name']);

        return [
            'phone' => $normalizedPhone,
            'days' => $days,
            'count' => $orders->count(),
            'orders' => $orders->map(fn (Order $o) => [
                'order_id' => $o->id,
                'order_number' => $o->order_number,
                'status' => $o->status->value,
                'product_name' => $o->product?->name ?? 'Unknown',
                'total_amount' => (float) $o->total_amount,
                'created_at' => $o->created_at?->toIso8601String(),
                'created_at_formatted' => $o->created_at?->format('M j, Y g:i A'),
            ])->values()->toArray(),
        ];
    }

    /**
     * Determine the severity level based on duplicate count and time window.
     *
     * @param int $count
     * @param int $windowHours
     * @return string  'none', 'low', 'medium', 'high'
     */
    private function determineSeverity(int $count, int $windowHours): string
    {
        if ($count === 0) {
            return 'none';
        }

        if ($count >= 3 || $windowHours <= 24) {
            return 'high';
        }

        if ($count >= 2 || $windowHours <= 48) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyResult(string $phone, int $windowHours): array
    {
        return [
            'is_duplicate' => false,
            'phone' => $phone,
            'product_ids' => [],
            'time_window_hours' => $windowHours,
            'duplicate_count' => 0,
            'duplicates' => [],
            'severity' => 'none',
        ];
    }
}
