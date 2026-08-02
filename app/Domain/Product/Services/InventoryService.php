<?php

declare(strict_types=1);

namespace App\Domain\Product\Services;

use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Product\Models\InventoryMovement;
use App\Domain\Product\Models\ProductStock;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    /**
     * Add stock (purchase, restock).
     */
    public function stockIn(
        int $productId,
        int $quantity,
        ?int $variantId = null,
        ?string $notes = null,
        ?int $performedBy = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $warehouseId = null,
    ): InventoryMovement {
        return DB::transaction(function () use ($productId, $quantity, $variantId, $notes, $performedBy, $referenceType, $referenceId, $warehouseId) {
            $warehouseId ??= $this->defaultWarehouseId();

            $stock = $this->getOrCreateStock($productId, $variantId, $warehouseId);
            $stock->current_stock += abs($quantity);
            $stock->last_restock_at = now();
            $stock->last_movement_at = now();
            $stock->save();

            $movement = InventoryMovement::create([
                'product_id' => $productId,
                'variant_id' => $variantId,
                'warehouse_id' => $warehouseId,
                'type' => 'STOCK_IN',
                'quantity' => abs($quantity),
                'notes' => $notes,
                'performed_by' => $performedBy,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ]);

            return $movement;
        });
    }

    /**
     * Remove stock (order fulfilled, shipped).
     */
    public function stockOut(
        int $productId,
        int $quantity,
        ?int $variantId = null,
        ?string $notes = null,
        ?int $performedBy = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $warehouseId = null,
    ): InventoryMovement {
        return DB::transaction(function () use ($productId, $quantity, $variantId, $notes, $performedBy, $referenceType, $referenceId, $warehouseId) {
            $warehouseId ??= $this->defaultWarehouseId();
            $stock = $this->getOrCreateStock($productId, $variantId, $warehouseId);

            if (($stock->current_stock - $stock->reserved_stock) < abs($quantity)) {
                throw new \RuntimeException(
                    "Insufficient stock. Available: {$stock->available_stock}, requested: {$quantity}"
                );
            }

            $movement = InventoryMovement::create([
                'product_id' => $productId,
                'variant_id' => $variantId,
                'warehouse_id' => $warehouseId,
                'type' => 'STOCK_OUT',
                'quantity' => -abs($quantity),
                'notes' => $notes,
                'performed_by' => $performedBy,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ]);

            $stock->current_stock -= abs($quantity);
            $stock->last_movement_at = now();
            $stock->save();

            return $movement;
        });
    }

    /**
     * Reserve stock for a pending order (doesn't reduce current_stock yet).
     */
    public function reserve(
        int $productId,
        int $quantity,
        ?int $variantId = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $warehouseId = null,
    ): InventoryMovement {
        return DB::transaction(function () use ($productId, $quantity, $variantId, $referenceType, $referenceId, $warehouseId) {
            $warehouseId ??= $this->defaultWarehouseId();
            $stock = $this->getOrCreateStock($productId, $variantId, $warehouseId);

            if ($stock->available_stock < $quantity) {
                throw new \RuntimeException("Insufficient stock. Available: {$stock->available_stock}, requested: {$quantity}");
            }

            $movement = InventoryMovement::create([
                'product_id' => $productId,
                'variant_id' => $variantId,
                'warehouse_id' => $warehouseId,
                'type' => 'RESERVATION',
                'quantity' => -abs($quantity),
                'notes' => 'Stock reserved for order',
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ]);

            $stock->reserved_stock += abs($quantity);
            $stock->last_movement_at = now();
            $stock->save();

            StockReservation::create([
                'product_id' => $productId,
                'variant_id' => $variantId,
                'warehouse_id' => $warehouseId,
                'quantity' => abs($quantity),
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'reserved_at' => now(),
                'expires_at' => now()->addHours(24),
                'status' => 'ACTIVE',
            ]);

            return $movement;
        });
    }

    /**
     * Release a reservation (order cancelled or returned).
     */
    public function release(
        int $productId,
        int $quantity,
        ?int $variantId = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $warehouseId = null,
    ): InventoryMovement {
        return DB::transaction(function () use ($productId, $quantity, $variantId, $referenceType, $referenceId, $warehouseId) {
            $warehouseId ??= $this->defaultWarehouseId();
            $movement = InventoryMovement::create([
                'product_id' => $productId,
                'variant_id' => $variantId,
                'warehouse_id' => $warehouseId,
                'type' => 'RELEASE',
                'quantity' => abs($quantity),
                'notes' => 'Reservation released',
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ]);

            $stock = $this->getOrCreateStock($productId, $variantId, $warehouseId);
            $stock->reserved_stock = max(0, $stock->reserved_stock - abs($quantity));
            $stock->last_movement_at = now();
            $stock->save();

            StockReservation::where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->where('status', 'ACTIVE')
                ->update([
                    'status' => 'RELEASED',
                    'released_at' => now(),
                    'released_reason' => 'manual',
                ]);

            return $movement;
        });
    }

    /**
     * Confirm a reservation — convert reserved to actual stock out.
     * Called when order is delivered.
     */
    public function confirmReservation(
        int $productId,
        int $quantity,
        ?int $variantId = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $warehouseId = null,
    ): InventoryMovement {
        return DB::transaction(function () use ($productId, $quantity, $variantId, $referenceType, $referenceId, $warehouseId) {
            $warehouseId ??= $this->defaultWarehouseId();
            $movement = InventoryMovement::create([
                'product_id' => $productId,
                'variant_id' => $variantId,
                'warehouse_id' => $warehouseId,
                'type' => 'STOCK_OUT',
                'quantity' => -abs($quantity),
                'notes' => 'Reservation confirmed — order delivered',
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ]);

            $stock = $this->getOrCreateStock($productId, $variantId, $warehouseId);
            if ($stock->current_stock < abs($quantity)) {
                throw new \RuntimeException(
                    "Insufficient stock for reservation confirmation. Current: {$stock->current_stock}, requested: {$quantity}"
                );
            }
            $stock->current_stock -= abs($quantity);
            $stock->reserved_stock = max(0, $stock->reserved_stock - abs($quantity));
            $stock->last_movement_at = now();
            $stock->save();

            StockReservation::where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->where('status', 'ACTIVE')
                ->update([
                    'status' => 'CONFIRMED',
                    'released_at' => now(),
                ]);

            return $movement;
        });
    }

    /**
     * Return stock (customer return).
     */
    public function returnStock(
        int $productId,
        int $quantity,
        ?int $variantId = null,
        ?string $notes = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $warehouseId = null,
    ): InventoryMovement {
        return DB::transaction(function () use ($productId, $quantity, $variantId, $notes, $referenceType, $referenceId, $warehouseId) {
            $warehouseId ??= $this->defaultWarehouseId();
            $movement = InventoryMovement::create([
                'product_id' => $productId,
                'variant_id' => $variantId,
                'warehouse_id' => $warehouseId,
                'type' => 'RETURN',
                'quantity' => abs($quantity),
                'notes' => $notes ?? 'Stock returned',
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ]);

            $stock = $this->getOrCreateStock($productId, $variantId, $warehouseId);
            $stock->current_stock += abs($quantity);
            $stock->last_movement_at = now();
            $stock->save();

            return $movement;
        });
    }

    /**
     * Manual stock adjustment.
     */
    public function adjustStock(
        int $productId,
        int $newQuantity,
        ?int $variantId = null,
        ?string $notes = null,
        ?int $performedBy = null,
        ?int $warehouseId = null,
    ): InventoryMovement {
        return DB::transaction(function () use ($productId, $newQuantity, $variantId, $notes, $performedBy, $warehouseId) {
            $warehouseId ??= $this->defaultWarehouseId();
            $stock = $this->getOrCreateStock($productId, $variantId, $warehouseId);
            $diff = $newQuantity - $stock->current_stock;

            $movement = InventoryMovement::create([
                'product_id' => $productId,
                'variant_id' => $variantId,
                'warehouse_id' => $warehouseId,
                'type' => 'ADJUSTMENT',
                'quantity' => $diff,
                'notes' => $notes ?? "Adjusted from {$stock->current_stock} to {$newQuantity}",
                'performed_by' => $performedBy,
            ]);

            $stock->current_stock = $newQuantity;
            $stock->last_movement_at = now();
            $stock->save();

            return $movement;
        });
    }

    /**
     * Get low stock products.
     */
    public function getLowStockProducts(): Collection
    {
        return ProductStock::whereRaw('(current_stock - reserved_stock) <= reorder_point')
            ->with('product')
            ->get();
    }

    private function getOrCreateStock(int $productId, ?int $variantId, ?int $warehouseId = null): ProductStock
    {
        return ProductStock::lockForUpdate()->firstOrCreate(
            ['product_id' => $productId, 'variant_id' => $variantId, 'warehouse_id' => $warehouseId],
            ['current_stock' => 0, 'reserved_stock' => 0, 'reorder_point' => 10]
        );
    }

    private ?int $defaultWarehouseIdCache = null;

    private function defaultWarehouseId(): ?int
    {
        return $this->defaultWarehouseIdCache ??= Warehouse::where('is_default', true)->value('id');
    }
}
