<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Models\SupplyMovement;
use App\Domain\Inventory\Models\SupplyStock;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SupplyStockService
{
    public function stockIn(
        int $supplyId,
        int $warehouseId,
        ?int $locationId,
        int $quantity,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $batchNumber = null,
        ?string $notes = null,
        ?int $performedBy = null,
    ): void {
        if ($quantity <= 0) {
            throw new RuntimeException('stockIn quantity must be positive.');
        }

        DB::transaction(function () use ($supplyId, $warehouseId, $locationId, $quantity, $referenceType, $referenceId, $batchNumber, $notes, $performedBy) {
            $stock = SupplyStock::lockForUpdate()->firstOrCreate(
                ['supply_id' => $supplyId, 'warehouse_id' => $warehouseId, 'location_id' => $locationId],
                ['current_stock' => 0, 'reserved_stock' => 0]
            );

            $stock->current_stock += $quantity;
            $stock->last_restock_at = now();
            $stock->save();

            SupplyMovement::create([
                'supply_id' => $supplyId,
                'warehouse_id' => $warehouseId,
                'location_id' => $locationId,
                'type' => 'STOCK_IN',
                'quantity' => $quantity,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'batch_number' => $batchNumber,
                'notes' => $notes,
                'performed_by' => $performedBy,
            ]);
        });
    }

    public function stockOut(
        int $supplyId,
        int $warehouseId,
        ?int $locationId,
        int $quantity,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $notes = null,
        ?int $performedBy = null,
    ): void {
        if ($quantity <= 0) {
            throw new RuntimeException('stockOut quantity must be positive.');
        }

        DB::transaction(function () use ($supplyId, $warehouseId, $locationId, $quantity, $referenceType, $referenceId, $notes, $performedBy) {
            $stock = SupplyStock::lockForUpdate()
                ->where('supply_id', $supplyId)
                ->where('warehouse_id', $warehouseId)
                ->where('location_id', $locationId)
                ->first();

            if (! $stock || ($stock->current_stock - $stock->reserved_stock) < $quantity) {
                throw new RuntimeException('Insufficient material stock.');
            }

            $stock->current_stock -= $quantity;
            $stock->save();

            SupplyMovement::create([
                'supply_id' => $supplyId,
                'warehouse_id' => $warehouseId,
                'location_id' => $locationId,
                'type' => 'STOCK_OUT',
                'quantity' => -$quantity,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'notes' => $notes,
                'performed_by' => $performedBy,
            ]);
        });
    }

    public function adjustStock(
        int $supplyId,
        int $warehouseId,
        ?int $locationId,
        int $newQuantity,
        ?string $notes = null,
        ?int $performedBy = null,
    ): void {
        if ($newQuantity < 0) {
            throw new RuntimeException('adjustStock quantity cannot be negative.');
        }

        DB::transaction(function () use ($supplyId, $warehouseId, $locationId, $newQuantity, $notes, $performedBy) {
            $stock = SupplyStock::lockForUpdate()->firstOrCreate(
                ['supply_id' => $supplyId, 'warehouse_id' => $warehouseId, 'location_id' => $locationId],
                ['current_stock' => 0, 'reserved_stock' => 0]
            );

            $before = (int) $stock->current_stock;
            $variance = $newQuantity - $before;

            $stock->current_stock = $newQuantity;
            $stock->save();

            SupplyMovement::create([
                'supply_id' => $supplyId,
                'warehouse_id' => $warehouseId,
                'location_id' => $locationId,
                'type' => 'ADJUSTMENT',
                'quantity' => $variance,
                'notes' => $notes ?? "Adjusted from {$before} to {$newQuantity}",
                'performed_by' => $performedBy,
            ]);
        });
    }
}
