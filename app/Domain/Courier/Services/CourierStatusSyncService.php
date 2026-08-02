<?php

declare(strict_types=1);

namespace App\Domain\Courier\Services;

use App\Domain\Courier\Models\CourierSyncLog;
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
        WaybillStatus::PENDING->value => null,
        WaybillStatus::DISPATCHED->value => OrderStatus::DISPATCHED->value,
        WaybillStatus::PICKED_UP->value => OrderStatus::DISPATCHED->value,
        WaybillStatus::IN_TRANSIT->value => OrderStatus::DISPATCHED->value,
        WaybillStatus::ARRIVED_HUB->value => OrderStatus::DISPATCHED->value,
        WaybillStatus::OUT_FOR_DELIVERY->value => OrderStatus::DISPATCHED->value,
        WaybillStatus::DELIVERY_FAILED->value => OrderStatus::ON_HOLD->value,
        WaybillStatus::DELIVERED->value => OrderStatus::DELIVERED->value,
        WaybillStatus::RETURNING->value => OrderStatus::ON_HOLD->value,
        WaybillStatus::RETURNED->value => OrderStatus::RETURNED->value,
        WaybillStatus::CANCELLED->value => OrderStatus::CANCELLED->value,
    ];

    public function __construct(
        private OrderFulfillmentService $fulfillment,
    ) {}

    public function syncWaybillToOrder(Waybill $waybill): ?Order
    {
        $order = Order::where('waybill_id', $waybill->id)->first();
        if (! $order) {
            return null;
        }

        $waybillStatus = $waybill->status instanceof WaybillStatus
            ? $waybill->status
            : WaybillStatus::tryFrom($waybill->status);

        if (! $waybillStatus) {
            return null;
        }

        $mappedOrderStatus = self::STATUS_MAP[$waybillStatus->value] ?? null;
        if (! $mappedOrderStatus) {
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
        if (! $order->conversation_id) {
            return;
        }

        $conversation = Conversation::find($order->conversation_id);
        if (! $conversation) {
            return;
        }

        $body = "📦 Order {$order->order_number} courier status: {$waybillStatus->label()} → order status: {$orderStatus->label()}";

        Message::query()->create([
            'conversation_id' => $order->conversation_id,
            'facebook_page_id' => $conversation->facebook_page_id,
            'sent_by' => null,
            'external_message_id' => 'system-'.str()->uuid(),
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

        $lastSync = CourierSyncLog::latest('id')->first();
        $lastSuccessfulSync = CourierSyncLog::where('status', 'completed')->latest('id')->first();

        $todayRuns = CourierSyncLog::whereDate('created_at', $today)->count();
        $todayUpdates = (int) CourierSyncLog::whereDate('created_at', $today)->sum('waybills_updated');
        $todayErrors = (int) CourierSyncLog::whereDate('created_at', $today)->sum('errors_count');

        $perCourier = $this->getPerCourierStats();

        return [
            'today_synced' => $syncedToday,
            'pending_sync' => $pendingSync,
            'orders_with_waybills' => $ordersWithWaybills,
            'today_delivered_via_sync' => $deliveredViaSync,
            'today_returned_via_sync' => $returnedViaSync,
            'auto_notify_customer' => $autoNotifyEnabled,
            'last_sync_at' => $lastSync?->created_at?->toIso8601String(),
            'last_sync_status' => $lastSync?->status,
            'last_successful_sync_at' => $lastSuccessfulSync?->created_at?->toIso8601String(),
            'today_runs' => $todayRuns,
            'today_updates' => $todayUpdates,
            'today_errors' => $todayErrors,
            'per_courier' => $perCourier,
        ];
    }

    public function getPerCourierStats(): array
    {
        $couriers = ['FLASH', 'JNT'];
        $result = [];

        foreach ($couriers as $code) {
            $pending = Waybill::query()
                ->where('courier_provider', $code)
                ->whereNotNull('waybill_number')
                ->whereNotIn('status', [
                    WaybillStatus::DELIVERED->value,
                    WaybillStatus::RETURNED->value,
                    WaybillStatus::CANCELLED->value,
                    WaybillStatus::PENDING->value,
                ])
                ->count();

            $total = Waybill::query()
                ->where('courier_provider', $code)
                ->whereNotNull('waybill_number')
                ->count();

            $delivered = Waybill::query()
                ->where('courier_provider', $code)
                ->where('status', WaybillStatus::DELIVERED->value)
                ->count();

            $inTransit = Waybill::query()
                ->where('courier_provider', $code)
                ->whereIn('status', [
                    WaybillStatus::DISPATCHED->value,
                    WaybillStatus::PICKED_UP->value,
                    WaybillStatus::IN_TRANSIT->value,
                    WaybillStatus::ARRIVED_HUB->value,
                    WaybillStatus::OUT_FOR_DELIVERY->value,
                ])
                ->count();

            $lastSync = CourierSyncLog::where('courier_code', $code)
                ->latest('id')
                ->first();

            $result[] = [
                'code' => $code,
                'total_waybills' => $total,
                'pending_sync' => $pending,
                'delivered' => $delivered,
                'in_transit' => $inTransit,
                'last_sync_at' => $lastSync?->created_at?->toIso8601String(),
                'last_sync_updated' => $lastSync?->waybills_updated ?? 0,
            ];
        }

        return $result;
    }

    public function getSyncHistory(int $limit = 20): array
    {
        return CourierSyncLog::latest('id')
            ->limit($limit)
            ->get()
            ->map(fn ($log) => [
                'id' => $log->id,
                'run_id' => $log->run_id,
                'courier_code' => $log->courier_code ?? 'ALL',
                'trigger' => $log->trigger,
                'waybills_checked' => $log->waybills_checked,
                'waybills_updated' => $log->waybills_updated,
                'waybills_unchanged' => $log->waybills_unchanged,
                'errors_count' => $log->errors_count,
                'duration_ms' => $log->duration_ms,
                'status' => $log->status,
                'created_at' => $log->created_at->toIso8601String(),
                'errors' => $log->errors ? array_slice($log->errors, 0, 5) : [],
            ])
            ->toArray();
    }

    public function logSyncRun(array $data): CourierSyncLog
    {
        return CourierSyncLog::create($data);
    }

    public function getSettings(): array
    {
        return [
            'auto_notify_customer' => SiteSetting::get('courier_status_sync_notify_customer', '1') === '1',
            'sync_intermediate_statuses' => SiteSetting::get('courier_status_sync_intermediate', '1') === '1',
            'sync_interval_minutes' => (int) SiteSetting::get('courier_sync_interval_minutes', '15'),
            'max_waybills_per_run' => (int) SiteSetting::get('courier_sync_max_waybills', '500'),
            'lookback_days' => (int) SiteSetting::get('courier_sync_lookback_days', '21'),
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
        if (array_key_exists('sync_interval_minutes', $settings)) {
            SiteSetting::set('courier_sync_interval_minutes', (string) max(5, min(1440, (int) $settings['sync_interval_minutes'])));
        }
        if (array_key_exists('max_waybills_per_run', $settings)) {
            SiteSetting::set('courier_sync_max_waybills', (string) max(10, min(5000, (int) $settings['max_waybills_per_run'])));
        }
        if (array_key_exists('lookback_days', $settings)) {
            SiteSetting::set('courier_sync_lookback_days', (string) max(1, min(90, (int) $settings['lookback_days'])));
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
