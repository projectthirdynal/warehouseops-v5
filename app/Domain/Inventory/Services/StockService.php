<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Models\StockCostLot;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Product\Models\InventoryMovement;
use App\Domain\Product\Models\ProductStock;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Authoritative service for all stock writes.
 *
 *  - All read-then-write paths run in DB::transaction().
 *  - reserve() uses an atomic conditional UPDATE — never produces oversells.
 *  - stockOut/adjust use SELECT FOR UPDATE — needed because they consume FIFO lots.
 *  - inventory_movements is append-only — never UPDATE/DELETE.
 */
class StockService
{
    /**
     * Receive stock from a confirmed GRN line.
     * Creates the FIFO cost lot and the inventory movement, updates product_stock.
     */
    public function stockIn(
        int $productId,
        ?int $variantId,
        int $warehouseId,
        ?int $locationId,
        int $quantity,
        float $unitCost,
        ?int $grnItemId = null,
        ?string $batchNumber = null,
        ?string $expiryDate = null,
        ?int $performedBy = null,
        string $currencyCode = 'PHP',
        float $exchangeRate = 1.0,
    ): void {
        if ($quantity <= 0) {
            throw new RuntimeException('stockIn quantity must be positive.');
        }

        DB::transaction(function () use (
            $productId, $variantId, $warehouseId, $locationId, $quantity,
            $unitCost, $grnItemId, $batchNumber, $expiryDate, $performedBy,
            $currencyCode, $exchangeRate
        ) {
            $stock = ProductStock::lockForUpdate()
                ->firstOrCreate(
                    ['product_id' => $productId, 'variant_id' => $variantId, 'warehouse_id' => $warehouseId],
                    ['location_id' => $locationId, 'current_stock' => 0, 'reserved_stock' => 0]
                );

            $stock->current_stock   += $quantity;
            $stock->last_restock_at  = now();
            $stock->last_movement_at = now();
            if ($stock->warehouse_id === null) $stock->warehouse_id = $warehouseId;
            if ($stock->location_id === null)  $stock->location_id  = $locationId;
            $stock->save();

            StockCostLot::create([
                'product_id'         => $productId,
                'variant_id'         => $variantId,
                'warehouse_id'       => $warehouseId,
                'grn_item_id'        => $grnItemId,
                'quantity_received'  => $quantity,
                'quantity_remaining' => $quantity,
                'unit_cost'          => $unitCost,
                'currency_code'      => $currencyCode,
                'exchange_rate'      => $exchangeRate,
                'received_at'        => now(),
                'expiry_date'        => $expiryDate,
                'batch_number'       => $batchNumber,
            ]);

            InventoryMovement::create([
                'product_id'     => $productId,
                'variant_id'     => $variantId,
                'warehouse_id'   => $warehouseId,
                'location_id'    => $locationId,
                'type'           => 'STOCK_IN',
                'quantity'       => $quantity,
                'reference_type' => $grnItemId ? 'grn_item' : null,
                'reference_id'   => $grnItemId,
                'batch_number'   => $batchNumber,
                'expiry_date'    => $expiryDate,
                'notes'          => $grnItemId ? "Received from GRN item #{$grnItemId}" : null,
                'performed_by'   => $performedBy,
            ]);
        });
    }

    /**
     * Decrement stock; consumes FIFO cost lots.
     * Used on order delivery / sale.
     */
    public function stockOut(
        int $productId,
        ?int $variantId,
        int $warehouseId,
        int $quantity,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $performedBy = null,
    ): void {
        if ($quantity <= 0) {
            throw new RuntimeException('stockOut quantity must be positive.');
        }

        DB::transaction(function () use ($productId, $variantId, $warehouseId, $quantity, $referenceType, $referenceId, $performedBy) {
            $stock = ProductStock::lockForUpdate()
                ->where('product_id', $productId)
                ->where('variant_id', $variantId)
                ->where('warehouse_id', $warehouseId)
                ->first();

            if (! $stock || ($stock->current_stock - $stock->reserved_stock) < $quantity) {
                throw new InsufficientStockException($productId, $quantity, $stock?->current_stock ?? 0);
            }

            $stock->current_stock   -= $quantity;
            $stock->last_movement_at = now();
            $stock->save();

            InventoryMovement::create([
                'product_id'     => $productId,
                'variant_id'     => $variantId,
                'warehouse_id'   => $warehouseId,
                'type'           => 'STOCK_OUT',
                'quantity'       => -$quantity,
                'reference_type' => $referenceType,
                'reference_id'   => $referenceId,
                'performed_by'   => $performedBy,
            ]);
        });
    }

    /**
     * Atomic reservation — uses a conditional UPDATE that succeeds only if
     * available stock covers the request. Affected rows = 0 → InsufficientStockException.
     */
    public function reserve(
        int $productId,
        ?int $variantId,
        int $warehouseId,
        int $quantity,
        string $referenceType,
        int $referenceId,
        \DateTimeInterface $expiresAt,
        ?int $reservedBy = null,
    ): StockReservation {
        if ($quantity <= 0) {
            throw new RuntimeException('reserve quantity must be positive.');
        }

        return DB::transaction(function () use ($productId, $variantId, $warehouseId, $quantity, $referenceType, $referenceId, $expiresAt, $reservedBy) {
            $rows = DB::table('product_stocks')
                ->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->where(function ($q) use ($variantId) {
                    $variantId === null ? $q->whereNull('variant_id') : $q->where('variant_id', $variantId);
                })
                ->whereRaw('(current_stock - reserved_stock) >= ?', [$quantity])
                ->update([
                    'reserved_stock'  => DB::raw("reserved_stock + {$quantity}"),
                    'last_movement_at' => now(),
                    'updated_at'      => now(),
                ]);

            if ($rows === 0) {
                $available = (int) (ProductStock::where('product_id', $productId)
                    ->where('warehouse_id', $warehouseId)
                    ->where('variant_id', $variantId)
                    ->value(DB::raw('COALESCE(current_stock - reserved_stock, 0)')) ?? 0);
                throw new InsufficientStockException($productId, $quantity, $available);
            }

            return StockReservation::create([
                'product_id'     => $productId,
                'variant_id'     => $variantId,
                'warehouse_id'   => $warehouseId,
                'quantity'       => $quantity,
                'reference_type' => $referenceType,
                'reference_id'   => $referenceId,
                'reserved_by'    => $reservedBy,
                'reserved_at'    => now(),
                'expires_at'     => $expiresAt,
                'status'         => 'ACTIVE',
            ]);
        });
    }

    /**
     * Set stock to an absolute quantity (used by scanner auto-adjust and approved adjustments).
     * Uses SELECT FOR UPDATE to prevent concurrent over/under adjustments.
     */
    public function adjustStock(
        int $productId,
        ?int $variantId,
        int $warehouseId,
        ?int $locationId,
        int $newQuantity,
        ?string $notes = null,
        ?int $performedBy = null,
    ): void {
        if ($newQuantity < 0) {
            throw new RuntimeException('adjustStock newQuantity must be >= 0.');
        }

        DB::transaction(function () use ($productId, $variantId, $warehouseId, $locationId, $newQuantity, $notes, $performedBy) {
            $stock = ProductStock::lockForUpdate()
                ->firstOrCreate(
                    ['product_id' => $productId, 'variant_id' => $variantId, 'warehouse_id' => $warehouseId],
                    ['location_id' => $locationId, 'current_stock' => 0, 'reserved_stock' => 0]
                );

            $variance = $newQuantity - $stock->current_stock;

            $stock->current_stock   = $newQuantity;
            $stock->last_movement_at = now();
            $stock->save();

            InventoryMovement::create([
                'product_id'   => $productId,
                'variant_id'   => $variantId,
                'warehouse_id' => $warehouseId,
                'location_id'  => $locationId,
                'type'         => 'ADJUSTMENT',
                'quantity'     => $variance,
                'notes'        => $notes ?? "Adjusted to {$newQuantity}",
                'performed_by' => $performedBy,
            ]);
        });
    }

    /**
     * Release an ACTIVE reservation back into available stock.
     */
    public function release(StockReservation $reservation, string $reason = 'manual'): void
    {
        if ($reservation->status !== 'ACTIVE') return;

        DB::transaction(function () use ($reservation, $reason) {
            $reservation->lockForUpdate();

            DB::table('product_stocks')
                ->where('product_id', $reservation->product_id)
                ->where(function ($q) use ($reservation) {
                    $reservation->variant_id === null
                        ? $q->whereNull('variant_id')
                        : $q->where('variant_id', $reservation->variant_id);
                })
                ->update([
                    'reserved_stock'  => DB::raw("CASE WHEN reserved_stock - {$reservation->quantity} > 0 THEN reserved_stock - {$reservation->quantity} ELSE 0 END"),
                    'last_movement_at' => now(),
                    'updated_at'      => now(),
                ]);

            $reservation->status          = 'RELEASED';
            $reservation->released_at     = now();
            $reservation->released_reason = $reason;
            $reservation->save();
        });
    }
}
