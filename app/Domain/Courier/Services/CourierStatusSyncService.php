<?php

declare(strict_types=1);

namespace App\Domain\Courier\Services;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Services\OrderFulfillmentService;
use App\Domain\Shop\Models\Conversation;
use App\Domain\Shop\Models\Message;
use App\Domain\Waybill\Enums\WaybillStatus;
use App\Models\SiteSetting;
use App\Models\Waybill;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CourierStatusSyncService
{
    private const STATUS_MAP = [
        WaybillStatus::PENDING->value         => null,
        WaybillStatus::DISPATCHED->value      => OrderStatus::DISPATCHED->value,
        WaybillStatus::PICKED_UP->value       => OrderStatus::DISPATCHED->value,
        WaybillStatus::IN_TRANSIT->value      => OrderStatus::DISPATCHED->value,
        WaybillStatus::ARRIVED_HUB->value     => OrderStatus::DISPATCHED->value,
        WaybillStatus::OUT_FOR_DELIVERY->value => OrderStatus::DISPATCHED->value,
        WaybillStatus::DELIVERY_FAILED->value  => OrderStatus::ON_HOLD->value,
        WaybillStatus::DELIVERED->value        => OrderStatus::DELIVERED->value,
        WaybillStatus::RETURNING->value        => OrderStatus::ON_HOLD->value,
        WaybillStatus::RETURNED->value         => OrderStatus::RETURNED->value,
        WaybillStatus::CANCELLED->value        => OrderStatus::CANCELLED->value,
    ];

    public function __construct(
        private OrderFulfillmentService $fulfillment,
    ) {}

    public function syncWaybillToOrder(Waybill $waybill): ?Order
    {
        $order = Order::where('waybill_id', $waybill->id)->first();
        if (!$order) {
            return null;
        }

        $waybillStatus = $waybill->status instanceof WaybillStatus
            ? $waybill->status
            : WaybillStatus::tryFrom($waybill->status);

        if (!$waybillStatus) {
            return null;
        }

        $mappedOrderStatus = self::STATUS_MAP[$waybillStatus->value] ?? null;
        if (!$mappedOrderStatus) {
            return null;
        }

        $newStatus = OrderStatus::from($mappedOrderStatus);

        if ($order->status === $newStatus) {
            return $order;
        }

        if ($order->status->isTerminal()) {
            Log::info("CourierStatusSync: skipping sync for order {$order->order_number} — already in terminal status {$order->status->value}");
            return $order;
        }

        $this->applyOrderStatusUpdate($order, $newStatus, $waybillStatus);

        return $order->fresh();
    }

    private function applyOrderStatusUpdate(Order $order, OrderStatus $newStatus, WaybillStatus $waybillStatus): void
    {
        switch ($newStatus) {
            case OrderStatus::DELIVERED:
                $this->fulfillment->handleDelivery($order);
                break;
            case OrderStatus::RETURNED:
                $this->fulfillment->handleReturn($order);
                break;
            case OrderStatus::CANCELLED:
                $this->handleCancellation($order);
                break;
            default:
                $this->updateOrderStatus($order, $newStatus, $waybillStatus);
                break;
        }
    }

    private function updateOrderStatus(Order $order, OrderStatus $newStatus, WaybillStatus $waybillStatus): void
    {
        DB::transaction(function () use ($order, $newStatus) {
            $order->update(['status' => $newStatus]);
        });

        $this->syncToConversation($order, $newStatus, $waybillStatus);
    }

    private function handleCancellation(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $order->update(['status' => OrderStatus::CANCELLED]);
        });

        $this->syncToConversation($order, OrderStatus::CANCELLED, WaybillStatus::CANCELLED);
    }

    private function syncToConversation(Order $order, OrderStatus $orderStatus, WaybillStatus $waybillStatus): void
    {
        if (!$order->conversation_id) {
            return;
        }

        $conversation = Conversation::find($order->conversation_id);
        if (!$conversation) {
            return;
        }

        $body = "📦 Order {$order->order_number} courier status: {$waybillStatus->label()} → order status: {$orderStatus->label()}";

        Message::query()->create([
            'conversation_id' => $order->conversation_id,
            'facebook_page_id' => $conversation->facebook_page_id,
            'sent_by' => null,
            'external_message_id' => 'system-' . str()->uuid(),
            'direction' => 'system',
            'message_type' => 'courier_status_sync',
            'body' => $body,
            'metadata' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'order_status' => $orderStatus->value,
                'waybill_status' => $waybillStatus->value,
            ],
            'sent_at' => now(),
            'send_status' => 'logged',
            'retry_count' => 0,
        ]);

        $conversation->forceFill([
            'last_message_preview' => $body,
            'last_message_at' => now(),
        ])->save();
    }

    public function bulkSync(): array
    {
        $waybills = Waybill::query()
            ->whereNotNull('waybill_number')
            ->where('courier_provider', '!=', 'MANUAL')
            ->whereNotIn('status', [
                WaybillStatus::DELIVERED->value,
                WaybillStatus::RETURNED->value,
                WaybillStatus::CANCELLED->value,
            ])
            ->whereHas('lead', function ($q) {
                $q->whereNotNull('id');
            })
            ->limit(100)
            ->get();

        $synced = 0;
        $skipped = 0;
        $errors = [];

        foreach ($waybills as $waybill) {
            try {
                $order = $this->syncWaybillToOrder($waybill);
                if ($order) {
                    $synced++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $skipped++;
                $errors[] = "Waybill #{$waybill->id}: {$e->getMessage()}";
            }
        }

        return [
            'synced' => $synced,
            'skipped' => $skipped,
            'errors' => $errors,
            'total' => $waybills->count(),
        ];
    }

    public function getStats(): array
    {
        $today = today();

        $syncedToday = Message::query()
            ->whereDate('created_at', $today)
            ->where('message_type', 'courier_status_sync')
            ->count();

        $pendingSync = Waybill::query()
            ->whereNotNull('waybill_number')
            ->where('courier_provider', '!=', 'MANUAL')
            ->whereNotIn('status', [
                WaybillStatus::DELIVERED->value,
                WaybillStatus::RETURNED->value,
                WaybillStatus::CANCELLED->value,
            ])
            ->whereHas('lead', function ($q) {
                $q->whereNotNull('id');
            })
            ->count();

        $ordersWithWaybills = Order::query()
            ->whereNotNull('waybill_id')
            ->count();

        $deliveredViaSync = Message::query()
            ->whereDate('created_at', $today)
            ->where('message_type', 'courier_status_sync')
            ->where('metadata->order_status', OrderStatus::DELIVERED->value)
            ->count();

        $returnedViaSync = Message::query()
            ->whereDate('created_at', $today)
            ->where('message_type', 'courier_status_sync')
            ->where('metadata->order_status', OrderStatus::RETURNED->value)
            ->count();

        $autoNotifyEnabled = SiteSetting::get('courier_status_sync_notify_customer', '1') === '1';

        return [
            'today_synced' => $syncedToday,
            'pending_sync' => $pendingSync,
            'orders_with_waybills' => $ordersWithWaybills,
            'today_delivered_via_sync' => $deliveredViaSync,
            'today_returned_via_sync' => $returnedViaSync,
            'auto_notify_customer' => $autoNotifyEnabled,
        ];
    }

    public function getSettings(): array
    {
        return [
            'auto_notify_customer' => SiteSetting::get('courier_status_sync_notify_customer', '1') === '1',
            'sync_intermediate_statuses' => SiteSetting::get('courier_status_sync_intermediate', '1') === '1',
            'status_map' => $this->getStatusMapDisplay(),
        ];
    }

    public function updateSettings(array $settings): void
    {
        if (array_key_exists('auto_notify_customer', $settings)) {
            SiteSetting::set('courier_status_sync_notify_customer', $settings['auto_notify_customer'] ? '1' : '0');
        }
        if (array_key_exists('sync_intermediate_statuses', $settings)) {
            SiteSetting::set('courier_status_sync_intermediate', $settings['sync_intermediate_statuses'] ? '1' : '0');
        }
    }

    private function getStatusMapDisplay(): array
    {
        $map = [];
        foreach (self::STATUS_MAP as $waybill => $order) {
            $map[] = [
                'waybill_status' => $waybill,
                'waybill_label' => WaybillStatus::tryFrom($waybill)?->label() ?? $waybill,
                'order_status' => $order ?? '(no sync)',
                'order_label' => $order ? (OrderStatus::tryFrom($order)?->label() ?? $order) : '(no sync)',
            ];
        }
        return $map;
    }
}
