<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Models\DeadStock;
use App\Domain\Inventory\Models\Supply;
use App\Domain\Inventory\Models\SupplyStock;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Product\Models\ProductStock;
use App\Models\SiteSetting;
use App\Models\User;
use App\Notifications\DeadStockAlertNotification;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

class DeadStockScanService
{
    public const BUCKET_SLOW = 'slow';

    public const BUCKET_NON_MOVING = 'non_moving';

    public const BUCKET_DEAD = 'dead';

    public const DEFAULT_SLOW_DAYS = 30;

    public const DEFAULT_NON_MOVING_DAYS = 60;

    public const DEFAULT_DEAD_DAYS = 90;

    /**
     * Get default settings.
     *
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        return [
            'slow_days' => (int) SiteSetting::get('dead_stock_slow_days', self::DEFAULT_SLOW_DAYS),
            'non_moving_days' => (int) SiteSetting::get('dead_stock_non_moving_days', self::DEFAULT_NON_MOVING_DAYS),
            'dead_days' => (int) SiteSetting::get('dead_stock_dead_days', self::DEFAULT_DEAD_DAYS),
            'auto_write_off' => (bool) SiteSetting::get('dead_stock_auto_write_off', false),
            'notify_emails' => (string) SiteSetting::get('dead_stock_notify_emails', ''),
            'notify_email_enabled' => (bool) SiteSetting::get('dead_stock_notify_email_enabled', true),
            'notify_in_app_enabled' => (bool) SiteSetting::get('dead_stock_notify_in_app_enabled', true),
            'min_value_threshold' => (float) SiteSetting::get('dead_stock_min_value_threshold', 0),
            'scan_frequency' => (string) SiteSetting::get('dead_stock_scan_frequency', 'daily'),
        ];
    }

    /**
     * Update settings.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateSettings(array $data): void
    {
        $keys = [
            'slow_days' => 'dead_stock_slow_days',
            'non_moving_days' => 'dead_stock_non_moving_days',
            'dead_days' => 'dead_stock_dead_days',
            'auto_write_off' => 'dead_stock_auto_write_off',
            'notify_emails' => 'dead_stock_notify_emails',
            'notify_email_enabled' => 'dead_stock_notify_email_enabled',
            'notify_in_app_enabled' => 'dead_stock_notify_in_app_enabled',
            'min_value_threshold' => 'dead_stock_min_value_threshold',
            'scan_frequency' => 'dead_stock_scan_frequency',
        ];

        foreach ($keys as $field => $settingKey) {
            if (array_key_exists($field, $data)) {
                SiteSetting::set($settingKey, $data[$field]);
            }
        }
    }

    /**
     * Run the dead stock scan.
     *
     * @return array<string, mixed>
     */
    public function scan(): array
    {
        $settings = $this->getSettings();

        $productItems = $this->scanProducts($settings);
        $supplyItems = $this->scanSupplies($settings);

        $allItems = $productItems->merge($supplyItems);

        // Filter by min value threshold
        if ($settings['min_value_threshold'] > 0) {
            $allItems = $allItems->filter(fn ($item) => $item['total_value'] >= $settings['min_value_threshold']);
        }

        // Build aging buckets
        $buckets = $this->buildAgingBuckets($allItems);

        // Auto-flag dead items
        $deadItems = $allItems->filter(fn ($item) => $item['bucket'] === self::BUCKET_DEAD);
        $flaggedCount = $this->autoFlagDeadItems($deadItems, $settings);

        // Send notifications
        $this->sendNotifications($allItems, $settings);

        return [
            'scanned_at' => now()->toDateTimeString(),
            'total_scanned' => $allItems->count(),
            'product_count' => $productItems->count(),
            'supply_count' => $supplyItems->count(),
            'flagged_count' => $flaggedCount,
            'buckets' => $buckets,
            'items' => $allItems->sortByDesc('total_value')->values()->take(100)->all(),
            'settings' => $settings,
        ];
    }

    /**
     * Get the current dead stock dashboard (without running a scan).
     *
     * @return array<string, mixed>
     */
    public function getDashboard(): array
    {
        $settings = $this->getSettings();

        $productItems = $this->scanProducts($settings);
        $supplyItems = $this->scanSupplies($settings);

        $allItems = $productItems->merge($supplyItems);

        if ($settings['min_value_threshold'] > 0) {
            $allItems = $allItems->filter(fn ($item) => $item['total_value'] >= $settings['min_value_threshold']);
        }

        $buckets = $this->buildAgingBuckets($allItems);

        // Dead stock write-off ledger stats
        $totalWriteOffs = DeadStock::count();
        $totalWriteOffValue = (float) DeadStock::sum('total_value');

        // By warehouse breakdown
        $byWarehouse = $this->buildWarehouseBreakdown($allItems);

        // Top dead stock items
        $topDeadItems = $allItems->filter(fn ($item) => $item['bucket'] === self::BUCKET_DEAD)
            ->sortByDesc('total_value')
            ->take(20)
            ->values()
            ->all();

        return [
            'summary' => [
                'total_items' => $allItems->count(),
                'total_value' => round($allItems->sum('total_value'), 2),
                'dead_count' => $allItems->filter(fn ($i) => $i['bucket'] === self::BUCKET_DEAD)->count(),
                'dead_value' => round($allItems->filter(fn ($i) => $i['bucket'] === self::BUCKET_DEAD)->sum('total_value'), 2),
                'non_moving_count' => $allItems->filter(fn ($i) => $i['bucket'] === self::BUCKET_NON_MOVING)->count(),
                'non_moving_value' => round($allItems->filter(fn ($i) => $i['bucket'] === self::BUCKET_NON_MOVING)->sum('total_value'), 2),
                'slow_count' => $allItems->filter(fn ($i) => $i['bucket'] === self::BUCKET_SLOW)->count(),
                'slow_value' => round($allItems->filter(fn ($i) => $i['bucket'] === self::BUCKET_SLOW)->sum('total_value'), 2),
                'total_write_offs' => $totalWriteOffs,
                'total_write_off_value' => round($totalWriteOffValue, 2),
            ],
            'buckets' => $buckets,
            'by_warehouse' => $byWarehouse,
            'top_dead_items' => $topDeadItems,
            'items' => $allItems->sortByDesc('total_value')->values()->take(100)->all(),
            'settings' => $settings,
        ];
    }

    /**
     * Scan products for dead stock.
     *
     * @param  array<string, mixed>  $settings
     * @return Collection<int, array<string, mixed>>
     */
    private function scanProducts(array $settings): Collection
    {
        $stocks = ProductStock::with(['product:id,sku,name,category,cost_price', 'warehouse:id,name,code'])
            ->whereHas('product', fn ($q) => $q->where('is_active', true))
            ->where('current_stock', '>', 0)
            ->get();

        return $stocks->map(function (ProductStock $stock) use ($settings) {
            $daysIdle = $this->calculateDaysIdle($stock->last_movement_at, $stock->created_at);
            $bucket = $this->classifyBucket($daysIdle, $settings);
            $unitCost = (float) ($stock->product->cost_price ?? 0);
            $totalValue = round($stock->current_stock * $unitCost, 2);

            return [
                'stream' => 'product',
                'item_id' => $stock->product_id,
                'stock_id' => $stock->id,
                'sku' => $stock->product?->sku ?? '—',
                'name' => $stock->product?->name ?? 'Unknown',
                'category' => $stock->product?->category ?? '—',
                'warehouse' => $stock->warehouse?->name ?? 'Default',
                'warehouse_id' => $stock->warehouse_id,
                'current_stock' => (int) $stock->current_stock,
                'reserved_stock' => (int) $stock->reserved_stock,
                'available_stock' => max(0, (int) $stock->current_stock - (int) $stock->reserved_stock),
                'unit_cost' => $unitCost,
                'total_value' => $totalValue,
                'last_movement_at' => $stock->last_movement_at?->toDateTimeString(),
                'days_idle' => $daysIdle,
                'bucket' => $bucket,
            ];
        })
            ->filter(fn ($item) => $item['days_idle'] >= $settings['slow_days'])
            ->toBase();
    }

    /**
     * Scan supplies for dead stock.
     *
     * @param  array<string, mixed>  $settings
     * @return Collection<int, array<string, mixed>>
     */
    private function scanSupplies(array $settings): Collection
    {
        $stocks = SupplyStock::with(['supply:id,sku,name,category,cost_price,stock_status', 'warehouse:id,name,code'])
            ->whereHas('supply', fn ($q) => $q->where('is_active', true)->whereNull('deleted_at'))
            ->where('current_stock', '>', 0)
            ->get();

        return $stocks->map(function (SupplyStock $stock) use ($settings) {
            $daysIdle = $this->calculateDaysIdle($stock->last_movement_at, $stock->created_at);
            $bucket = $this->classifyBucket($daysIdle, $settings);
            $unitCost = (float) ($stock->supply?->cost_price ?? 0);
            $totalValue = round($stock->current_stock * $unitCost, 2);

            return [
                'stream' => 'supply',
                'item_id' => $stock->supply_id,
                'stock_id' => $stock->id,
                'sku' => $stock->supply?->sku ?? '—',
                'name' => $stock->supply?->name ?? 'Unknown',
                'category' => $stock->supply?->category ?? $stock->supply?->stock_category ?? '—',
                'warehouse' => $stock->warehouse?->name ?? 'Default',
                'warehouse_id' => $stock->warehouse_id,
                'current_stock' => (int) $stock->current_stock,
                'reserved_stock' => (int) $stock->reserved_stock,
                'available_stock' => max(0, (int) $stock->current_stock - (int) $stock->reserved_stock),
                'unit_cost' => $unitCost,
                'total_value' => $totalValue,
                'last_movement_at' => $stock->last_movement_at?->toDateTimeString(),
                'days_idle' => $daysIdle,
                'bucket' => $bucket,
            ];
        })
            ->filter(fn ($item) => $item['days_idle'] >= $settings['slow_days'])
            ->toBase();
    }

    /**
     * Calculate days since last movement.
     *
     * @param  CarbonInterface|string|null  $lastMovementAt
     * @param  CarbonInterface|string|null  $createdAt
     */
    private function calculateDaysIdle(mixed $lastMovementAt, mixed $createdAt): int
    {
        $reference = $lastMovementAt ?? $createdAt ?? now();

        if ($reference instanceof \Carbon\CarbonInterface) {
            return (int) abs(now()->diffInDays($reference));
        }

        return (int) abs(now()->diffInDays(\Carbon\Carbon::parse($reference)));
    }

    /**
     * Classify item into aging bucket.
     *
     * @param  array<string, mixed>  $settings
     */
    private function classifyBucket(int $daysIdle, array $settings): string
    {
        if ($daysIdle >= $settings['dead_days']) {
            return self::BUCKET_DEAD;
        }

        if ($daysIdle >= $settings['non_moving_days']) {
            return self::BUCKET_NON_MOVING;
        }

        return self::BUCKET_SLOW;
    }

    /**
     * Build aging bucket summary.
     *
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function buildAgingBuckets(Collection $items): array
    {
        $settings = $this->getSettings();

        $bucketConfig = [
            self::BUCKET_SLOW => [
                'label' => "Slow ({$settings['slow_days']}–{$settings['non_moving_days']}d)",
                'color' => 'warning',
            ],
            self::BUCKET_NON_MOVING => [
                'label' => "Non-Moving ({$settings['non_moving_days']}–{$settings['dead_days']}d)",
                'color' => 'orange',
            ],
            self::BUCKET_DEAD => [
                'label' => "Dead ({$settings['dead_days']}d+)",
                'color' => 'destructive',
            ],
        ];

        $result = [];

        foreach ($bucketConfig as $key => $config) {
            $bucketItems = $items->filter(fn ($item) => $item['bucket'] === $key);
            $result[] = [
                'bucket' => $key,
                'label' => $config['label'],
                'color' => $config['color'],
                'count' => $bucketItems->count(),
                'total_value' => round($bucketItems->sum('total_value'), 2),
                'product_count' => $bucketItems->filter(fn ($i) => $i['stream'] === 'product')->count(),
                'supply_count' => $bucketItems->filter(fn ($i) => $i['stream'] === 'supply')->count(),
            ];
        }

        return $result;
    }

    /**
     * Build warehouse breakdown.
     *
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function buildWarehouseBreakdown(Collection $items): array
    {
        return $items->groupBy('warehouse_id')
            ->map(function ($group, $warehouseId) {
                $first = $group->first();

                return [
                    'warehouse_id' => $warehouseId,
                    'warehouse' => $first['warehouse'],
                    'total_items' => $group->count(),
                    'total_value' => round($group->sum('total_value'), 2),
                    'dead_count' => $group->filter(fn ($i) => $i['bucket'] === self::BUCKET_DEAD)->count(),
                    'dead_value' => round($group->filter(fn ($i) => $i['bucket'] === self::BUCKET_DEAD)->sum('total_value'), 2),
                ];
            })
            ->sortByDesc('total_value')
            ->values()
            ->all();
    }

    /**
     * Auto-flag dead items — update supply stock_status and optionally auto write-off.
     *
     * @param  Collection<int, array<string, mixed>>  $deadItems
     * @param  array<string, mixed>  $settings
     */
    private function autoFlagDeadItems(Collection $deadItems, array $settings): int
    {
        $flagged = 0;

        // Flag supplies as DEAD
        $supplyIds = $deadItems->filter(fn ($i) => $i['stream'] === 'supply')
            ->pluck('item_id')
            ->unique()
            ->values()
            ->all();

        if (! empty($supplyIds)) {
            $updated = Supply::whereIn('id', $supplyIds)
                ->where('stock_status', '!=', Supply::STATUS_DEAD)
                ->where('stock_status_override', false)
                ->update(['stock_status' => Supply::STATUS_DEAD]);
            $flagged += $updated;
        }

        // Auto write-off if enabled
        if ($settings['auto_write_off']) {
            foreach ($deadItems as $item) {
                $existing = DeadStock::where('item_type', $item['stream'])
                    ->where($item['stream'] === 'supply' ? 'supply_id' : 'product_id', $item['item_id'])
                    ->where('warehouse_id', $item['warehouse_id'])
                    ->whereDate('created_at', today())
                    ->exists();

                if (! $existing) {
                    DeadStock::create([
                        'item_type' => $item['stream'],
                        'supply_id' => $item['stream'] === 'supply' ? $item['item_id'] : null,
                        'product_id' => $item['stream'] === 'product' ? $item['item_id'] : null,
                        'warehouse_id' => $item['warehouse_id'],
                        'quantity' => $item['available_stock'],
                        'unit_cost' => $item['unit_cost'],
                        'total_value' => $item['total_value'],
                        'reason' => "Auto-flagged: no movement for {$item['days_idle']} days",
                        'recorded_by' => null,
                    ]);
                    $flagged++;
                }
            }
        }

        return $flagged;
    }

    /**
     * Send notifications for dead stock items.
     *
     * @param  Collection<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $settings
     */
    private function sendNotifications(Collection $items, array $settings): void
    {
        $deadItems = $items->filter(fn ($i) => $i['bucket'] === self::BUCKET_DEAD);

        if ($deadItems->isEmpty()) {
            return;
        }

        $notifyEmails = $settings['notify_email_enabled']
            ? array_filter(array_map('trim', explode(',', (string) $settings['notify_emails'])))
            : [];

        $notifyUsers = collect();

        if ($settings['notify_in_app_enabled']) {
            $roles = ['superadmin', 'admin', 'warehouse', 'supervisor'];
            $notifyUsers = User::whereIn('role', $roles)->where('is_active', true)->get();
        }

        // Add users matching notify emails
        if (! empty($notifyEmails)) {
            $emailUsers = User::whereIn('email', $notifyEmails)->where('is_active', true)->get();
            $notifyUsers = $notifyUsers->merge($emailUsers)->unique('id');
        }

        if ($notifyUsers->isEmpty()) {
            return;
        }

        $summary = [
            'total_dead' => $deadItems->count(),
            'total_dead_value' => round($deadItems->sum('total_value'), 2),
            'top_items' => $deadItems->sortByDesc('total_value')->take(10)->values()->all(),
            'scanned_at' => now()->toDateTimeString(),
        ];

        Notification::send($notifyUsers, new DeadStockAlertNotification($summary, $settings['notify_email_enabled']));
    }

    /**
     * Export scan results as CSV.
     */
    public function exportCsv(): string
    {
        $settings = $this->getSettings();
        $productItems = $this->scanProducts($settings);
        $supplyItems = $this->scanSupplies($settings);
        $allItems = $productItems->merge($supplyItems);

        if ($settings['min_value_threshold'] > 0) {
            $allItems = $allItems->filter(fn ($item) => $item['total_value'] >= $settings['min_value_threshold']);
        }

        $allItems = $allItems->sortByDesc('total_value')->values();

        $lines = [];
        $lines[] = 'DEAD STOCK SCAN REPORT';
        $lines[] = 'Generated,'.now()->toDateTimeString();
        $lines[] = "Thresholds,Slow:{$settings['slow_days']}d, Non-Moving:{$settings['non_moving_days']}d, Dead:{$settings['dead_days']}d";
        $lines[] = '';
        $lines[] = 'SUMMARY';
        $lines[] = 'Bucket,Count,Value';
        foreach ($this->buildAgingBuckets($allItems) as $b) {
            $lines[] = "{$b['label']},{$b['count']},".number_format($b['total_value'], 2, '.', '');
        }
        $lines[] = '';
        $lines[] = 'ITEM DETAIL';
        $lines[] = 'Stream,SKU,Name,Category,Warehouse,Current Stock,Reserved,Available,Unit Cost,Total Value,Days Idle,Bucket,Last Movement';

        foreach ($allItems as $item) {
            $lines[] = implode(',', [
                $item['stream'],
                $this->csvEscape($item['sku']),
                $this->csvEscape($item['name']),
                $this->csvEscape($item['category']),
                $this->csvEscape($item['warehouse']),
                $item['current_stock'],
                $item['reserved_stock'],
                $item['available_stock'],
                number_format($item['unit_cost'], 4, '.', ''),
                number_format($item['total_value'], 2, '.', ''),
                $item['days_idle'],
                $item['bucket'],
                $item['last_movement_at'] ?? '—',
            ]);
        }

        return implode("\n", $lines);
    }

    private function csvEscape(string $value): string
    {
        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }
}
