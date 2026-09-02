<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use App\Models\SiteSetting;
use App\Models\User;
use App\Notifications\ReorderPointAlertNotification;
use Illuminate\Support\Facades\Notification;
use Modules\Inventory\Models\StockAlert;
use Modules\Inventory\Models\Supply;
use Modules\Inventory\Models\SupplyStock;
use Modules\Inventory\Models\Warehouse;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductStock;

class ReorderPointAlertService
{
    /**
     * Scan all product and supply stock, create/resolve alerts, and send notifications.
     *
     * @return array{created: int, resolved: int, notified: int}
     */
    public function scanAndNotify(): array
    {
        $created = 0;
        $resolved = 0;
        $notified = 0;

        // Resolve stale alerts first
        StockAlert::where('alert_type', StockAlert::TYPE_LOW_STOCK)
            ->whereIn('status', [StockAlert::STATUS_OPEN, StockAlert::STATUS_ACKNOWLEDGED])
            ->chunkById(100, function ($alerts) use (&$resolved) {
                foreach ($alerts as $alert) {
                    if (! $this->alertStillValid($alert)) {
                        $alert->update(['status' => StockAlert::STATUS_RESOLVED]);
                        $resolved++;
                    }
                }
            });

        // Scan product stocks
        ProductStock::with(['product', 'warehouse'])
            ->whereHas('product', fn ($q) => $q->where('is_active', true))
            ->where('reorder_point', '>', 0)
            ->chunk(100, function ($stocks) use (&$created, &$notified) {
                foreach ($stocks as $stock) {
                    $result = $this->ensureAlert($stock, 'App\\Domain\\Product\\Models\\ProductStock');
                    if ($result['created']) {
                        $created++;
                    }
                    if ($result['notify']) {
                        $notified += $this->notifySubscribers($stock, 'product');
                    }
                }
            });

        // Scan supply stocks
        SupplyStock::with(['supply', 'warehouse'])
            ->whereHas('supply', fn ($q) => $q->where('is_active', true)->whereNull('deleted_at'))
            ->where('reorder_point', '>', 0)
            ->chunk(100, function ($stocks) use (&$created, &$notified) {
                foreach ($stocks as $stock) {
                    $result = $this->ensureAlert($stock, 'App\\Domain\\Inventory\\Models\\SupplyStock');
                    if ($result['created']) {
                        $created++;
                    }
                    if ($result['notify']) {
                        $notified += $this->notifySubscribers($stock, 'supply');
                    }
                }
            });

        return ['created' => $created, 'resolved' => $resolved, 'notified' => $notified];
    }

    /**
     * Get paginated reorder alerts with filters.
     *
     * @return array<string, mixed>
     */
    public function getAlerts(array $filters = []): array
    {
        $query = StockAlert::with(['stockable', 'warehouse', 'acknowledgedBy'])
            ->where('alert_type', StockAlert::TYPE_LOW_STOCK)
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $wid) => $q->where('warehouse_id', $wid))
            ->when($filters['stockable_type'] ?? null, function ($q, $type) {
                $morphMap = [
                    'product' => 'App\\Domain\\Product\\Models\\ProductStock',
                    'supply' => 'App\\Domain\\Inventory\\Models\\SupplyStock',
                ];
                $q->where('stockable_type', $morphMap[$type] ?? $type);
            });

        $perPage = min(100, max(10, (int) ($filters['per_page'] ?? 20)));
        $paginated = $query->latest()->paginate($perPage, ['*'], 'page', (int) ($filters['page'] ?? 1));

        $items = $paginated->getCollection()->map(function (StockAlert $alert) {
            $stockable = $alert->stockable;
            $itemName = 'Unknown';
            $itemSku = '—';
            $stream = 'product';

            if ($stockable instanceof ProductStock) {
                $itemName = $stockable->product?->name ?? 'Unknown';
                $itemSku = $stockable->product?->sku ?? '—';
                $stream = 'product';
            } elseif ($stockable instanceof SupplyStock) {
                $itemName = $stockable->supply?->name ?? 'Unknown';
                $itemSku = $stockable->supply?->sku ?? '—';
                $stream = 'supply';
            }

            return [
                'id' => $alert->id,
                'stream' => $stream,
                'item_name' => $itemName,
                'item_sku' => $itemSku,
                'warehouse' => $alert->warehouse?->name ?? 'Default',
                'warehouse_id' => $alert->warehouse_id,
                'current_stock' => $alert->current_stock,
                'reserved_stock' => $alert->reserved_stock,
                'available_stock' => max(0, $alert->current_stock - $alert->reserved_stock),
                'reorder_point' => $alert->reorder_point,
                'suggested_reorder_qty' => $alert->suggested_reorder_qty,
                'status' => $alert->status,
                'acknowledged_by' => $alert->acknowledgedBy?->name,
                'acknowledged_at' => $alert->acknowledged_at?->toIso8601String(),
                'notes' => $alert->notes,
                'created_at' => $alert->created_at->toIso8601String(),
            ];
        });

        return [
            'data' => $items,
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
            'per_page' => $paginated->perPage(),
            'total' => $paginated->total(),
            'from' => $paginated->firstItem(),
            'to' => $paginated->lastItem(),
        ];
    }

    /**
     * Get summary stats for reorder alerts.
     *
     * @return array<string, mixed>
     */
    public function getSummary(): array
    {
        $base = StockAlert::where('alert_type', StockAlert::TYPE_LOW_STOCK);

        return [
            'total_open' => (clone $base)->where('status', StockAlert::STATUS_OPEN)->count(),
            'total_acknowledged' => (clone $base)->where('status', StockAlert::STATUS_ACKNOWLEDGED)->count(),
            'total_resolved' => (clone $base)->where('status', StockAlert::STATUS_RESOLVED)->count(),
            'product_alerts' => (clone $base)
                ->where('stockable_type', 'App\\Domain\\Product\\Models\\ProductStock')
                ->where('status', StockAlert::STATUS_OPEN)
                ->count(),
            'supply_alerts' => (clone $base)
                ->where('stockable_type', 'App\\Domain\\Inventory\\Models\\SupplyStock')
                ->where('status', StockAlert::STATUS_OPEN)
                ->count(),
            'by_warehouse' => Warehouse::where('is_active', true)
                ->get()
                ->map(fn (Warehouse $w) => [
                    'id' => $w->id,
                    'name' => $w->name,
                    'code' => $w->code,
                    'open_alerts' => (clone $base)
                        ->where('warehouse_id', $w->id)
                        ->where('status', StockAlert::STATUS_OPEN)
                        ->count(),
                ])
                ->filter(fn ($w) => $w['open_alerts'] > 0)
                ->values()
                ->all(),
        ];
    }

    /**
     * Get alert settings.
     */
    public function getSettings(): array
    {
        return [
            'notify_emails' => SiteSetting::get('reorder_notify_emails', ''),
            'notify_roles' => array_filter(explode(',', (string) SiteSetting::get('reorder_notify_roles', 'warehouse,supervisor'))),
            'notify_email_enabled' => filter_var(SiteSetting::get('reorder_email_enabled', 'true'), FILTER_VALIDATE_BOOLEAN),
            'notify_in_app_enabled' => filter_var(SiteSetting::get('reorder_in_app_enabled', 'true'), FILTER_VALIDATE_BOOLEAN),
            'scan_frequency' => SiteSetting::get('reorder_scan_frequency', 'daily'),
            'reorder_multiplier' => (int) SiteSetting::get('reorder_multiplier', '3'),
        ];
    }

    /**
     * Update alert settings.
     */
    public function updateSettings(array $data): void
    {
        SiteSetting::set('reorder_notify_emails', $data['notify_emails'] ?? '');
        SiteSetting::set('reorder_notify_roles', implode(',', $data['notify_roles'] ?? ['warehouse', 'supervisor']));
        SiteSetting::set('reorder_email_enabled', ($data['notify_email_enabled'] ?? false) ? 'true' : 'false');
        SiteSetting::set('reorder_in_app_enabled', ($data['notify_in_app_enabled'] ?? false) ? 'true' : 'false');
        SiteSetting::set('reorder_scan_frequency', $data['scan_frequency'] ?? 'daily');
        SiteSetting::set('reorder_multiplier', (string) ($data['reorder_multiplier'] ?? 3));
    }

    /**
     * Acknowledge an alert.
     */
    public function acknowledgeAlert(StockAlert $alert, int $userId, ?string $notes = null): StockAlert
    {
        $alert->update([
            'status' => StockAlert::STATUS_ACKNOWLEDGED,
            'acknowledged_by' => $userId,
            'acknowledged_at' => now(),
            'notes' => $notes,
        ]);

        return $alert;
    }

    /**
     * Ensure an alert exists for a stock item. Returns whether a new alert was created and whether to notify.
     *
     * @return array{created: bool, notify: bool}
     */
    private function ensureAlert(ProductStock|SupplyStock $stock, string $morphType): array
    {
        $available = max(0, $stock->current_stock - $stock->reserved_stock);
        $reorderPoint = (int) $stock->reorder_point;

        if ($reorderPoint <= 0 || $available > $reorderPoint) {
            return ['created' => false, 'notify' => false];
        }

        $multiplier = (int) SiteSetting::get('reorder_multiplier', '3');
        $suggestedQty = max($reorderPoint * $multiplier - $stock->current_stock, 0);

        $existing = StockAlert::where('stockable_type', $morphType)
            ->where('stockable_id', $stock->id)
            ->where('alert_type', StockAlert::TYPE_LOW_STOCK)
            ->whereIn('status', [StockAlert::STATUS_OPEN, StockAlert::STATUS_ACKNOWLEDGED])
            ->first();

        if ($existing) {
            // Update existing alert with latest values — no new notification
            $existing->update([
                'current_stock' => $stock->current_stock,
                'reserved_stock' => $stock->reserved_stock,
                'reorder_point' => $reorderPoint,
                'suggested_reorder_qty' => $suggestedQty,
                'warehouse_id' => $stock->warehouse_id,
            ]);

            return ['created' => false, 'notify' => false];
        }

        StockAlert::create([
            'stockable_type' => $morphType,
            'stockable_id' => $stock->id,
            'warehouse_id' => $stock->warehouse_id,
            'alert_type' => StockAlert::TYPE_LOW_STOCK,
            'current_stock' => $stock->current_stock,
            'reserved_stock' => $stock->reserved_stock,
            'reorder_point' => $reorderPoint,
            'suggested_reorder_qty' => $suggestedQty,
            'status' => StockAlert::STATUS_OPEN,
        ]);

        return ['created' => true, 'notify' => true];
    }

    /**
     * Notify subscribers about a reorder point alert.
     */
    private function notifySubscribers(ProductStock|SupplyStock $stock, string $stream): int
    {
        $settings = $this->getSettings();
        $notified = 0;

        if (! $settings['notify_email_enabled'] && ! $settings['notify_in_app_enabled']) {
            return 0;
        }

        $itemName = $stream === 'product'
            ? ($stock->product?->name ?? 'Unknown Product')
            : ($stock->supply?->name ?? 'Unknown Supply');
        $itemSku = $stream === 'product'
            ? ($stock->product?->sku ?? '—')
            : ($stock->supply?->sku ?? '—');
        $warehouseName = $stock->warehouse?->name ?? 'Default';
        $available = max(0, $stock->current_stock - $stock->reserved_stock);

        $notificationData = [
            'item_name' => $itemName,
            'item_sku' => $itemSku,
            'stream' => $stream,
            'warehouse' => $warehouseName,
            'current_stock' => $stock->current_stock,
            'reserved_stock' => $stock->reserved_stock,
            'available_stock' => $available,
            'reorder_point' => (int) $stock->reorder_point,
            'suggested_reorder_qty' => max((int) $stock->reorder_point * (int) ($settings['reorder_multiplier'] ?? 3) - $stock->current_stock, 0),
        ];

        // Collect in-app notification recipients (users with configured roles)
        if ($settings['notify_in_app_enabled'] && ! empty($settings['notify_roles'])) {
            $users = User::whereIn('role', $settings['notify_roles'])
                ->where('is_active', true)
                ->get();

            foreach ($users as $user) {
                $user->notify(new ReorderPointAlertNotification($notificationData, $settings['notify_email_enabled']));
                $notified++;
            }
        }

        // Send email to additional configured addresses
        if ($settings['notify_email_enabled'] && ! empty($settings['notify_emails'])) {
            $emails = array_filter(array_map('trim', explode(',', $settings['notify_emails'])));
            foreach ($emails as $email) {
                $route = Notification::route('mail', $email);
                $route->notify(new ReorderPointAlertNotification($notificationData, true));
                $notified++;
            }
        }

        return $notified;
    }

    /**
     * Check if an existing alert is still valid.
     */
    private function alertStillValid(StockAlert $alert): bool
    {
        $stock = $alert->stockable;
        if (! $stock) {
            return false;
        }

        $available = max(0, $stock->current_stock - $stock->reserved_stock);

        return $stock->reorder_point > 0 && $available <= $stock->reorder_point;
    }
}
