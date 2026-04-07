<?php

declare(strict_types=1);

namespace App\Domain\Product\Services;

use App\Domain\Product\Models\InventoryMovement;
use App\Domain\Product\Models\Product;
use App\Domain\Product\Models\ProductStock;
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
    ): InventoryMovement {
        return DB::transaction(function () use ($productId, $quantity, $variantId, $notes, $performedBy, $referenceType, $referenceId) {
            $movement = InventoryMovement::create([
                'product_id'     => $productId,
                'variant_id'     => $variantId,
                'type'           => 'STOCK_IN',
                'quantity'       => abs($quantity),
                'notes'          => $notes,
                'performed_by'   => $performedBy,
                'reference_type' => $referenceType,
                'reference_id'   => $referenceId,
            ]);

            $stock = $this->getOrCreateStock($productId, $variantId);
            $stock->increment('current_stock', abs($quantity));
            $stock->update(['last_restock_at' => now()]);

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
    ): InventoryMovement {
        return DB::transaction(function () use ($productId, $quantity, $variantId, $notes, $performedBy, $referenceType, $referenceId) {
            $movement = InventoryMovement::create([
                'product_id'     => $productId,
                'variant_id'     => $variantId,
                'type'           => 'STOCK_OUT',
                'quantity'       => -abs($quantity),
                'notes'          => $notes,
                'performed_by'   => $performedBy,
                'reference_type' => $referenceType,
                'reference_id'   => $referenceId,
            ]);

            $stock = $this->getOrCreateStock($productId, $variantId);
            $stock->decrement('current_stock', abs($quantity));

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
    ): InventoryMovement {
        return DB::transaction(function () use ($productId, $quantity, $variantId, $referenceType, $referenceId) {
            $stock = $this->getOrCreateStock($productId, $variantId);

            if ($stock->available_stock < $quantity) {
                throw new \RuntimeException("Insufficient stock. Available: {$stock->available_stock}, requested: {$quantity}");
            }

            $movement = InventoryMovement::create([
                'product_id'     => $productId,
                'variant_id'     => $variantId,
                'type'           => 'RESERVATION',
                'quantity'       => -abs($quantity),
                'notes'          => 'Stock reserved for order',
                'reference_type' => $referenceType,
                'reference_id'   => $referenceId,
            ]);

            $stock->increment('reserved_stock', abs($quantity));

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
    ): InventoryMovement {
        return DB::transaction(function () use ($productId, $quantity, $variantId, $referenceType, $referenceId) {
            $movement = InventoryMovement::create([
                'product_id'     => $productId,
                'variant_id'     => $variantId,
                'type'           => 'RELEASE',
                'quantity'       => abs($quantity),
                'notes'          => 'Reservation released',
                'reference_type' => $referenceType,
                'reference_id'   => $referenceId,
            ]);

            $stock = $this->getOrCreateStock($productId, $variantId);
            $stock->decrement('reserved_stock', min(abs($quantity), $stock->reserved_stock));

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
    ): InventoryMovement {
        return DB::transaction(function () use ($productId, $quantity, $variantId, $referenceType, $referenceId) {
            $movement = InventoryMovement::create([
                'product_id'     => $productId,
                'variant_id'     => $variantId,
                'type'           => 'STOCK_OUT',
                'quantity'       => -abs($quantity),
                'notes'          => 'Reservation confirmed — order delivered',
                'reference_type' => $referenceType,
                'reference_id'   => $referenceId,
            ]);

            $stock = $this->getOrCreateStock($productId, $variantId);
            $stock->decrement('current_stock', abs($quantity));
            $stock->decrement('reserved_stock', min(abs($quantity), $stock->reserved_stock));

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
    ): InventoryMovement {
        return DB::transaction(function () use ($productId, $quantity, $variantId, $notes, $referenceType, $referenceId) {
            $movement = InventoryMovement::create([
                'product_id'     => $productId,
                'variant_id'     => $variantId,
                'type'           => 'RETURN',
                'quantity'       => abs($quantity),
                'notes'          => $notes ?? 'Stock returned',
                'reference_type' => $referenceType,
                'reference_id'   => $referenceId,
            ]);

            $stock = $this->getOrCreateStock($productId, $variantId);
            $stock->increment('current_stock', abs($quantity));

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
    ): InventoryMovement {
        return DB::transaction(function () use ($productId, $newQuantity, $variantId, $notes, $performedBy) {
            $stock = $this->getOrCreateStock($productId, $variantId);
            $diff = $newQuantity - $stock->current_stock;

            $movement = InventoryMovement::create([
                'product_id'   => $productId,
                'variant_id'   => $variantId,
                'type'         => 'ADJUSTMENT',
                'quantity'     => $diff,
                'notes'        => $notes ?? "Adjusted from {$stock->current_stock} to {$newQuantity}",
                'performed_by' => $performedBy,
            ]);

            $stock->update(['current_stock' => $newQuantity]);

            return $movement;
        });
    }

    /**
     * Get low stock products.
     */
    public function getLowStockProducts(): \Illuminate\Database\Eloquent\Collection
    {
        return ProductStock::whereRaw('(current_stock - reserved_stock) <= reorder_point')
            ->with('product')
            ->get();
    }

    private function getOrCreateStock(int $productId, ?int $variantId): ProductStock
    {
        return ProductStock::firstOrCreate(
            ['product_id' => $productId, 'variant_id' => $variantId],
            ['current_stock' => 0, 'reserved_stock' => 0, 'reorder_point' => 10]
        );
    }
}
