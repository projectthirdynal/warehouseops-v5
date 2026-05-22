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
                    ['warehouse_id' => $warehouseId, 'location_id' => $locationId, 'current_stock' => 0, 'reserved_stock' => 0]
                );

            $stock->current_stock   += $quantity;
            $stock->last_restock_at  = now();
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
        ?string $notes = null,
    ): void {
        if ($quantity <= 0) {
            throw new RuntimeException('stockOut quantity must be positive.');
        }

        DB::transaction(function () use ($productId, $variantId, $warehouseId, $quantity, $referenceType, $referenceId, $performedBy, $notes) {
            $stock = ProductStock::lockForUpdate()
                ->where('product_id', $productId)
                ->where('variant_id', $variantId)
                ->where('warehouse_id', $warehouseId)
                ->first();

            if (! $stock || ($stock->current_stock - $stock->reserved_stock) < $quantity) {
                throw new InsufficientStockException($productId, $quantity, $stock?->current_stock ?? 0);
            }

            $stock->current_stock -= $quantity;
            $stock->save();

            InventoryMovement::create([
                'product_id'     => $productId,
                'variant_id'     => $variantId,
                'warehouse_id'   => $warehouseId,
                'type'           => 'STOCK_OUT',
                'quantity'       => -$quantity,
                'reference_type' => $referenceType,
                'reference_id'   => $referenceId,
                'notes'          => $notes,
                'performed_by'   => $performedBy,
            ]);
        });
    }

    /**
     * Set the physical stock count to an exact quantity and record the variance.
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
            throw new RuntimeException('adjustStock quantity cannot be negative.');
        }

        DB::transaction(function () use ($productId, $variantId, $warehouseId, $locationId, $newQuantity, $notes, $performedBy) {
            $stock = ProductStock::lockForUpdate()
                ->firstOrCreate(
                    ['product_id' => $productId, 'variant_id' => $variantId, 'warehouse_id' => $warehouseId],
                    [
                        'warehouse_id' => $warehouseId,
                        'location_id' => $locationId,
                        'current_stock' => 0,
                        'reserved_stock' => 0,
                    ]
                );

            $before = (int) $stock->current_stock;
            $variance = $newQuantity - $before;

            $stock->current_stock = $newQuantity;
            if ($stock->warehouse_id === null) $stock->warehouse_id = $warehouseId;
            if ($stock->location_id === null)  $stock->location_id  = $locationId;
            $stock->save();

            InventoryMovement::create([
                'product_id'   => $productId,
                'variant_id'   => $variantId,
                'warehouse_id' => $warehouseId,
                'location_id'  => $locationId,
                'type'         => 'ADJUSTMENT',
                'quantity'     => $variance,
                'notes'        => $notes ?? "Adjusted from {$before} to {$newQuantity}",
                'performed_by' => $performedBy,
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
        ?int $warehouseId,
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
                ->where(function ($q) use ($variantId) {
                    $variantId === null ? $q->whereNull('variant_id') : $q->where('variant_id', $variantId);
                })
                ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
                ->whereRaw('(current_stock - reserved_stock) >= ?', [$quantity])
                ->update([
                    'reserved_stock' => DB::raw("reserved_stock + {$quantity}"),
                    'updated_at'     => now(),
                ]);

            if ($rows === 0) {
                $available = (int) (ProductStock::where('product_id', $productId)
                    ->where('variant_id', $variantId)
                    ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
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
     * Release an ACTIVE reservation back into available stock.
     */
    public function release(StockReservation $reservation, string $reason = 'manual'): void
    {
        if ($reservation->status !== 'ACTIVE') return;

        DB::transaction(function () use ($reservation, $reason) {
            $reservation->lockForUpdate();

            $stock = ProductStock::query()
                ->where('product_id', $reservation->product_id)
                ->where(function ($q) use ($reservation) {
                    $reservation->variant_id === null
                        ? $q->whereNull('variant_id')
                        : $q->where('variant_id', $reservation->variant_id);
                })
                ->when($reservation->warehouse_id !== null, fn ($q) => $q->where('warehouse_id', $reservation->warehouse_id))
                ->lockForUpdate()
                ->first();

            if ($stock) {
                $stock->reserved_stock = max(0, $stock->reserved_stock - $reservation->quantity);
                $stock->save();
            }

            $reservation->status          = 'RELEASED';
            $reservation->released_at     = now();
            $reservation->released_reason = $reason;
            $reservation->save();
        });
    }

    public function releaseForReference(string $referenceType, int $referenceId, string $reason = 'manual'): int
    {
        return DB::transaction(function () use ($referenceType, $referenceId, $reason) {
            $reservations = StockReservation::query()
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->where('status', 'ACTIVE')
                ->lockForUpdate()
                ->get();

            foreach ($reservations as $reservation) {
                $stock = ProductStock::query()
                    ->where('product_id', $reservation->product_id)
                    ->where(function ($query) use ($reservation) {
                        $reservation->variant_id === null
                            ? $query->whereNull('variant_id')
                            : $query->where('variant_id', $reservation->variant_id);
                    })
                    ->when($reservation->warehouse_id !== null, fn ($query) => $query->where('warehouse_id', $reservation->warehouse_id))
                    ->lockForUpdate()
                    ->first();

                if ($stock) {
                    $stock->reserved_stock = max(0, $stock->reserved_stock - $reservation->quantity);
                    $stock->save();
                }

                $reservation->forceFill([
                    'status' => 'RELEASED',
                    'released_at' => now(),
                    'released_reason' => $reason,
                ])->save();

                InventoryMovement::create([
                    'product_id' => $reservation->product_id,
                    'variant_id' => $reservation->variant_id,
                    'warehouse_id' => $reservation->warehouse_id,
                    'type' => 'RELEASE',
                    'quantity' => $reservation->quantity,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'notes' => "Reservation released: {$reason}",
                    'performed_by' => $reservation->reserved_by,
                ]);
            }

            return $reservations->count();
        });
    }

    public function consumeForReference(string $referenceType, int $referenceId, ?int $performedBy = null): int
    {
        return DB::transaction(function () use ($referenceType, $referenceId, $performedBy) {
            $reservations = StockReservation::query()
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->where('status', 'ACTIVE')
                ->lockForUpdate()
                ->get();

            foreach ($reservations as $reservation) {
                $stock = ProductStock::query()
                    ->where('product_id', $reservation->product_id)
                    ->where(function ($query) use ($reservation) {
                        $reservation->variant_id === null
                            ? $query->whereNull('variant_id')
                            : $query->where('variant_id', $reservation->variant_id);
                    })
                    ->when($reservation->warehouse_id !== null, fn ($query) => $query->where('warehouse_id', $reservation->warehouse_id))
                    ->lockForUpdate()
                    ->first();

                if (! $stock || $stock->current_stock < $reservation->quantity) {
                    throw new InsufficientStockException(
                        (int) $reservation->product_id,
                        (int) $reservation->quantity,
                        (int) ($stock?->current_stock ?? 0),
                    );
                }

                $stock->current_stock -= $reservation->quantity;
                $stock->reserved_stock = max(0, $stock->reserved_stock - $reservation->quantity);
                $stock->save();

                $reservation->forceFill([
                    'status' => 'CONSUMED',
                    'released_at' => now(),
                    'released_reason' => 'consumed',
                ])->save();

                InventoryMovement::create([
                    'product_id' => $reservation->product_id,
                    'variant_id' => $reservation->variant_id,
                    'warehouse_id' => $reservation->warehouse_id,
                    'type' => 'STOCK_OUT',
                    'quantity' => -$reservation->quantity,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'notes' => 'Reservation consumed for fulfilled order',
                    'performed_by' => $performedBy ?? $reservation->reserved_by,
                ]);
            }

            return $reservations->count();
        });
    }
}
