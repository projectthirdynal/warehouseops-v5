<?php

declare(strict_types=1);

namespace Modules\Waybills\Services;

use App\Models\User;
use App\Notifications\ReturnProcessedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Modules\Orders\Models\Order;
use Modules\Products\Services\InventoryService;
use Modules\Waybills\Models\ReturnReceipt;
use Modules\Waybills\Models\Waybill;

class ReturnWorkflowService
{
    public function __construct(
        private InventoryService $inventoryService,
    ) {}

    public function scanAndProcess(string $waybillNumber, int $userId, string $condition = 'GOOD', ?string $notes = null): array
    {
        $waybill = Waybill::where('waybill_number', strtoupper(trim($waybillNumber)))
            ->with(['returnReceipt', 'lead'])
            ->first();

        if (! $waybill) {
            return [
                'status' => 'not_found',
                'message' => 'Waybill not found in system.',
                'waybill_number' => $waybillNumber,
            ];
        }

        $statusValue = is_string($waybill->status) ? $waybill->status : $waybill->status->value;

        if ($statusValue !== 'RETURNED') {
            return [
                'status' => 'wrong_status',
                'message' => "Cannot receive — status is {$statusValue}, expected RETURNED.",
                'waybill' => $this->formatWaybill($waybill),
            ];
        }

        if ($waybill->returnReceipt) {
            return [
                'status' => 'already_processed',
                'message' => 'Return already received.',
                'waybill' => $this->formatWaybill($waybill),
                'receipt' => $this->formatReceipt($waybill->returnReceipt),
            ];
        }

        return DB::transaction(function () use ($waybill, $userId, $condition, $notes) {
            $receipt = ReturnReceipt::create([
                'waybill_id' => $waybill->id,
                'scanned_by' => $userId,
                'scanned_at' => now(),
                'condition' => $condition,
                'notes' => $notes,
            ]);

            $inventoryResult = $this->updateInventory($waybill, $receipt);
            $financeResult = $this->notifyFinance($waybill, $receipt);

            $receipt->update([
                'inventory_updated' => $inventoryResult['success'],
                'inventory_movement_id' => $inventoryResult['movement_id'] ?? null,
                'finance_notified' => $financeResult,
                'processed_at' => now(),
            ]);

            Log::info('Return processed', [
                'waybill_id' => $waybill->id,
                'receipt_id' => $receipt->id,
                'condition' => $condition,
                'inventory' => $inventoryResult,
                'finance_notified' => $financeResult,
            ]);

            return [
                'status' => 'success',
                'message' => 'Return received, inventory updated, finance notified.',
                'waybill' => $this->formatWaybill($waybill),
                'receipt' => $this->formatReceipt($receipt->fresh()),
                'inventory' => $inventoryResult,
                'finance_notified' => $financeResult,
            ];
        });
    }

    public function batchScan(array $waybillNumbers, int $userId, string $condition = 'GOOD', ?string $notes = null): array
    {
        $results = [];
        foreach ($waybillNumbers as $number) {
            $results[] = $this->scanAndProcess($number, $userId, $condition, $notes);
        }

        return [
            'results' => $results,
            'summary' => [
                'total' => count($results),
                'success' => count(array_filter($results, fn ($r) => $r['status'] === 'success')),
                'not_found' => count(array_filter($results, fn ($r) => $r['status'] === 'not_found')),
                'wrong_status' => count(array_filter($results, fn ($r) => $r['status'] === 'wrong_status')),
                'already_processed' => count(array_filter($results, fn ($r) => $r['status'] === 'already_processed')),
            ],
        ];
    }

    public function getDashboardData(array $filters = []): array
    {
        $from = $filters['from'] ?? now()->subDays(30)->toDateString();
        $to = $filters['to'] ?? now()->toDateString();

        $query = ReturnReceipt::with(['waybill:id,waybill_number,status,courier_provider,receiver_name,city,amount,cod_amount,item_name,item_qty,returned_at', 'scannedBy:id,name'])
            ->whereHas('waybill')
            ->whereDate('scanned_at', '>=', $from)
            ->whereDate('scanned_at', '<=', $to)
            ->when($filters['condition'] ?? null, fn ($q, $v) => $q->where('condition', $v))
            ->latest('scanned_at');

        $receipts = (clone $query)->limit(100)->get();
        $total = (clone $query)->count();

        $todayCount = ReturnReceipt::whereDate('scanned_at', today())->count();
        $pendingInventory = ReturnReceipt::where('inventory_updated', false)->count();
        $pendingFinance = ReturnReceipt::where('finance_notified', false)->count();
        $damagedCount = ReturnReceipt::where('condition', 'DAMAGED')->whereDate('scanned_at', '>=', $from)->count();

        $codAtRisk = Waybill::where('status', 'RETURNED')
            ->whereDoesntHave('returnReceipt')
            ->whereDate('returned_at', '>=', $from)
            ->sum(\DB::raw('COALESCE(cod_amount, amount)'));

        $pendingReturns = Waybill::where('status', 'RETURNED')
            ->whereDoesntHave('returnReceipt')
            ->with(['returnReceipt'])
            ->latest('returned_at')
            ->limit(50)
            ->get();

        return [
            'summary' => [
                'total_received' => $total,
                'today_count' => $todayCount,
                'pending_inventory' => $pendingInventory,
                'pending_finance' => $pendingFinance,
                'damaged_count' => $damagedCount,
                'cod_at_risk' => (float) $codAtRisk,
                'pending_returns' => $pendingReturns->count(),
            ],
            'receipts' => $receipts->map(fn ($r) => $this->formatReceipt($r)),
            'pending' => $pendingReturns->map(fn ($w) => $this->formatWaybill($w)),
            'filters' => ['from' => $from, 'to' => $to, 'condition' => $filters['condition'] ?? null],
        ];
    }

    private function updateInventory(Waybill $waybill, ReturnReceipt $receipt): array
    {
        $order = Order::where('lead_id', $waybill->lead_id)->first();

        if (! $order || ! $order->product_id) {
            return [
                'success' => false,
                'message' => 'No linked product found for inventory update.',
                'product_id' => null,
            ];
        }

        $quantity = (int) ($waybill->item_qty ?? $order->quantity ?? 1);
        if ($quantity < 1) {
            $quantity = 1;
        }

        try {
            $movement = $this->inventoryService->returnStock(
                productId: $order->product_id,
                quantity: $quantity,
                variantId: $order->variant_id,
                notes: "Return receipt #{$receipt->id} — Waybill {$waybill->waybill_number} ({$receipt->condition})",
                referenceType: 'ReturnReceipt',
                referenceId: $receipt->id,
            );

            return [
                'success' => true,
                'message' => "Stock returned: +{$quantity} units.",
                'product_id' => $order->product_id,
                'quantity' => $quantity,
                'movement_id' => $movement->id,
            ];
        } catch (\Exception $e) {
            Log::error('Inventory update failed for return', [
                'receipt_id' => $receipt->id,
                'waybill_id' => $waybill->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Inventory update failed: '.$e->getMessage(),
                'product_id' => $order->product_id,
            ];
        }
    }

    private function notifyFinance(Waybill $waybill, ReturnReceipt $receipt): bool
    {
        try {
            $financeUsers = User::whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'finance', 'supervisor']))
                ->orWhere('is_admin', true)
                ->get();

            if ($financeUsers->isEmpty()) {
                Log::warning('No finance users found to notify for return', ['receipt_id' => $receipt->id]);

                return false;
            }

            Notification::send($financeUsers, new ReturnProcessedNotification($receipt));

            return true;
        } catch (\Exception $e) {
            Log::error('Finance notification failed for return', [
                'receipt_id' => $receipt->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function formatWaybill(Waybill $w): array
    {
        return [
            'id' => $w->id,
            'waybill_number' => $w->waybill_number,
            'status' => is_string($w->status) ? $w->status : $w->status?->value,
            'courier' => $w->courier_provider,
            'receiver_name' => $w->receiver_name,
            'city' => $w->city,
            'cod_amount' => (float) ($w->cod_amount ?? $w->amount ?? 0),
            'item_name' => $w->item_name,
            'item_qty' => (int) ($w->item_qty ?? 0),
            'returned_at' => $w->returned_at?->toIso8601String(),
        ];
    }

    private function formatReceipt(ReturnReceipt $r): array
    {
        return [
            'id' => $r->id,
            'waybill_id' => $r->waybill_id,
            'condition' => $r->condition,
            'notes' => $r->notes,
            'scanned_at' => $r->scanned_at?->toIso8601String(),
            'processed_at' => $r->processed_at?->toIso8601String(),
            'inventory_updated' => $r->inventory_updated,
            'finance_notified' => $r->finance_notified,
            'scanned_by' => $r->scannedBy?->name,
            'waybill' => $r->waybill ? $this->formatWaybill($r->waybill) : null,
        ];
    }
}
