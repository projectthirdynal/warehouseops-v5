<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Models\SupplyMovement;
use App\Domain\Inventory\Models\SupplyStock;
use App\Domain\Product\Models\InventoryMovement;
use App\Domain\Product\Models\ProductStock;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StockTransferService
{
    public function __construct(
        private MovementAuditTrailService $auditService
    ) {}

    /**
     * Create a pending stock transfer request.
     *
     * @param  array<string, mixed>  $data
     */
    public function createTransfer(array $data, User $requester): StockTransfer
    {
        return DB::transaction(function () use ($data, $requester) {
            $fromWarehouseId = (int) $data['from_warehouse_id'];
            $toWarehouseId = (int) $data['to_warehouse_id'];

            if ($fromWarehouseId === $toWarehouseId) {
                throw new RuntimeException('Source and destination warehouses must be different.');
            }

            $quantity = (int) $data['quantity'];
            if ($quantity <= 0) {
                throw new RuntimeException('Transfer quantity must be greater than zero.');
            }

            $stockableType = $data['stockable_type']; // Product or Supply class
            $stockableId = (int) $data['stockable_id'];
            $variantId = isset($data['variant_id']) ? (int) $data['variant_id'] : null;

            $this->guardSufficientStock($stockableType, $stockableId, $variantId, $fromWarehouseId, $quantity);

            return StockTransfer::create([
                'stockable_type' => $stockableType,
                'stockable_id' => $stockableId,
                'variant_id' => $variantId,
                'from_warehouse_id' => $fromWarehouseId,
                'to_warehouse_id' => $toWarehouseId,
                'from_location_id' => $data['from_location_id'] ?? null,
                'to_location_id' => $data['to_location_id'] ?? null,
                'quantity' => $quantity,
                'status' => StockTransfer::STATUS_PENDING,
                'requested_by' => $requester->id,
                'reason_notes' => $data['reason_notes'] ?? null,
            ]);
        });
    }

    public function approve(StockTransfer $transfer, User $approver, ?Request $request = null): void
    {
        if ($transfer->status !== StockTransfer::STATUS_PENDING) {
            throw new RuntimeException("Transfer already processed (status: {$transfer->status}).");
        }

        DB::transaction(function () use ($transfer, $approver) {
            $quantity = (int) $transfer->quantity;

            if ($transfer->stockable_type === 'App\\Domain\\Product\\Models\\Product') {
                $this->executeProductTransfer($transfer, $quantity);
            } elseif ($transfer->stockable_type === 'App\\Domain\\Inventory\\Models\\Supply') {
                $this->executeSupplyTransfer($transfer, $quantity);
            } else {
                throw new RuntimeException('Unsupported stockable type for transfer.');
            }

            $transfer->update([
                'status' => StockTransfer::STATUS_COMPLETED,
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ]);
        });
    }

    public function reject(StockTransfer $transfer, User $approver, ?string $reason = null): void
    {
        if ($transfer->status !== StockTransfer::STATUS_PENDING) {
            throw new RuntimeException("Transfer already processed (status: {$transfer->status}).");
        }

        $transfer->update([
            'status' => StockTransfer::STATUS_REJECTED,
            'approved_by' => $approver->id,
            'approved_at' => now(),
            'reason_notes' => trim(($transfer->reason_notes ?? '')."\n[REJECTED] ".($reason ?? 'No reason provided')),
        ]);
    }

    public function cancel(StockTransfer $transfer, User $user): void
    {
        if ($transfer->status !== StockTransfer::STATUS_PENDING) {
            throw new RuntimeException('Only pending transfers can be cancelled.');
        }

        $transfer->update([
            'status' => StockTransfer::STATUS_CANCELLED,
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);
    }

    private function executeProductTransfer(StockTransfer $transfer, int $quantity): void
    {
        $fromStock = ProductStock::lockForUpdate()
            ->firstOrCreate(
                [
                    'product_id' => $transfer->stockable_id,
                    'variant_id' => $transfer->variant_id,
                    'warehouse_id' => $transfer->from_warehouse_id,
                ],
                ['location_id' => $transfer->from_location_id, 'current_stock' => 0, 'reserved_stock' => 0]
            );

        if (($fromStock->current_stock - $fromStock->reserved_stock) < $quantity) {
            throw new RuntimeException('Insufficient available stock at source warehouse for transfer.');
        }

        $beforeFromQty = (int) $fromStock->current_stock;
        $beforeFromReserved = (int) $fromStock->reserved_stock;
        $fromStock->current_stock -= $quantity;
        $fromStock->last_movement_at = now();
        $fromStock->save();

        $toStock = ProductStock::lockForUpdate()
            ->firstOrCreate(
                [
                    'product_id' => $transfer->stockable_id,
                    'variant_id' => $transfer->variant_id,
                    'warehouse_id' => $transfer->to_warehouse_id,
                ],
                ['location_id' => $transfer->to_location_id, 'current_stock' => 0, 'reserved_stock' => 0]
            );

        $beforeToQty = (int) $toStock->current_stock;
        $beforeToReserved = (int) $toStock->reserved_stock;
        $toStock->current_stock += $quantity;
        $toStock->last_movement_at = now();
        $toStock->save();

        $sourceMovement = InventoryMovement::create([
            'product_id' => $transfer->stockable_id,
            'variant_id' => $transfer->variant_id,
            'warehouse_id' => $transfer->from_warehouse_id,
            'location_id' => $transfer->from_location_id,
            'type' => 'TRANSFER_OUT',
            'quantity' => -$quantity,
            'reference_type' => StockTransfer::class,
            'reference_id' => $transfer->id,
            'notes' => "Transfer out to warehouse #{$transfer->to_warehouse_id}",
            'performed_by' => $transfer->approved_by,
        ]);

        $destinationMovement = InventoryMovement::create([
            'product_id' => $transfer->stockable_id,
            'variant_id' => $transfer->variant_id,
            'warehouse_id' => $transfer->to_warehouse_id,
            'location_id' => $transfer->to_location_id,
            'type' => 'TRANSFER_IN',
            'quantity' => $quantity,
            'reference_type' => StockTransfer::class,
            'reference_id' => $transfer->id,
            'notes' => "Transfer in from warehouse #{$transfer->from_warehouse_id}",
            'performed_by' => $transfer->approved_by,
        ]);

        $transfer->update([
            'source_movement_type' => InventoryMovement::class,
            'source_movement_id' => $sourceMovement->id,
            'destination_movement_type' => InventoryMovement::class,
            'destination_movement_id' => $destinationMovement->id,
        ]);

        $this->auditService->recordProductMovement(
            $sourceMovement,
            beforeQuantity: $beforeFromQty,
            afterQuantity: $beforeFromQty - $quantity,
            beforeReserved: $beforeFromReserved,
            afterReserved: $beforeFromReserved,
            reasonNotes: "Transfer #{$transfer->id} out to warehouse #{$transfer->to_warehouse_id}",
            request: null,
        );

        $this->auditService->recordProductMovement(
            $destinationMovement,
            beforeQuantity: $beforeToQty,
            afterQuantity: $beforeToQty + $quantity,
            beforeReserved: $beforeToReserved,
            afterReserved: $beforeToReserved,
            reasonNotes: "Transfer #{$transfer->id} in from warehouse #{$transfer->from_warehouse_id}",
            request: null,
        );
    }

    private function executeSupplyTransfer(StockTransfer $transfer, int $quantity): void
    {
        $fromStock = SupplyStock::lockForUpdate()
            ->firstOrCreate(
                [
                    'supply_id' => $transfer->stockable_id,
                    'warehouse_id' => $transfer->from_warehouse_id,
                    'location_id' => $transfer->from_location_id,
                ],
                ['current_stock' => 0, 'reserved_stock' => 0, 'reorder_point' => 0]
            );

        if (($fromStock->current_stock - $fromStock->reserved_stock) < $quantity) {
            throw new RuntimeException('Insufficient available stock at source warehouse for transfer.');
        }

        $beforeFromQty = (int) $fromStock->current_stock;
        $beforeFromReserved = (int) $fromStock->reserved_stock;
        $fromStock->current_stock -= $quantity;
        $fromStock->last_movement_at = now();
        $fromStock->save();

        $toStock = SupplyStock::lockForUpdate()
            ->firstOrCreate(
                [
                    'supply_id' => $transfer->stockable_id,
                    'warehouse_id' => $transfer->to_warehouse_id,
                    'location_id' => $transfer->to_location_id,
                ],
                ['current_stock' => 0, 'reserved_stock' => 0, 'reorder_point' => 0]
            );

        $beforeToQty = (int) $toStock->current_stock;
        $beforeToReserved = (int) $toStock->reserved_stock;
        $toStock->current_stock += $quantity;
        $toStock->last_movement_at = now();
        $toStock->save();

        $sourceMovement = SupplyMovement::create([
            'supply_id' => $transfer->stockable_id,
            'warehouse_id' => $transfer->from_warehouse_id,
            'location_id' => $transfer->from_location_id,
            'type' => 'TRANSFER_OUT',
            'quantity' => -$quantity,
            'reference_type' => StockTransfer::class,
            'reference_id' => $transfer->id,
            'notes' => "Transfer out to warehouse #{$transfer->to_warehouse_id}",
            'performed_by' => $transfer->approved_by,
        ]);

        $destinationMovement = SupplyMovement::create([
            'supply_id' => $transfer->stockable_id,
            'warehouse_id' => $transfer->to_warehouse_id,
            'location_id' => $transfer->to_location_id,
            'type' => 'TRANSFER_IN',
            'quantity' => $quantity,
            'reference_type' => StockTransfer::class,
            'reference_id' => $transfer->id,
            'notes' => "Transfer in from warehouse #{$transfer->from_warehouse_id}",
            'performed_by' => $transfer->approved_by,
        ]);

        $transfer->update([
            'source_movement_type' => SupplyMovement::class,
            'source_movement_id' => $sourceMovement->id,
            'destination_movement_type' => SupplyMovement::class,
            'destination_movement_id' => $destinationMovement->id,
        ]);

        $this->auditService->recordSupplyMovement(
            $sourceMovement,
            beforeQuantity: $beforeFromQty,
            afterQuantity: $beforeFromQty - $quantity,
            beforeReserved: $beforeFromReserved,
            afterReserved: $beforeFromReserved,
            reasonNotes: "Transfer #{$transfer->id} out to warehouse #{$transfer->to_warehouse_id}",
            request: null,
        );

        $this->auditService->recordSupplyMovement(
            $destinationMovement,
            beforeQuantity: $beforeToQty,
            afterQuantity: $beforeToQty + $quantity,
            beforeReserved: $beforeToReserved,
            afterReserved: $beforeToReserved,
            reasonNotes: "Transfer #{$transfer->id} in from warehouse #{$transfer->from_warehouse_id}",
            request: null,
        );
    }

    private function guardSufficientStock(
        string $stockableType,
        int $stockableId,
        ?int $variantId,
        int $warehouseId,
        int $quantity,
    ): void {
        if ($stockableType === 'App\\Domain\\Product\\Models\\Product') {
            $available = (int) (ProductStock::where('product_id', $stockableId)
                ->where('warehouse_id', $warehouseId)
                ->when($variantId !== null, fn ($q) => $q->where('variant_id', $variantId))
                ->when($variantId === null, fn ($q) => $q->whereNull('variant_id'))
                ->selectRaw('COALESCE(current_stock - reserved_stock, 0) as available')
                ->value('available') ?? 0);
        } elseif ($stockableType === 'App\\Domain\\Inventory\\Models\\Supply') {
            $available = (int) (SupplyStock::where('supply_id', $stockableId)
                ->where('warehouse_id', $warehouseId)
                ->selectRaw('COALESCE(current_stock - reserved_stock, 0) as available')
                ->value('available') ?? 0);
        } else {
            throw new RuntimeException('Unsupported stockable type.');
        }

        if ($available < $quantity) {
            throw new RuntimeException("Insufficient available stock at source warehouse. Available: {$available}, requested: {$quantity}.");
        }
    }
}
