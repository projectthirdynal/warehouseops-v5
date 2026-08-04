<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Models\MovementAuditTrail;
use App\Domain\Inventory\Models\SupplyMovement;
use App\Domain\Product\Models\InventoryMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MovementAuditTrailService
{
    /**
     * Record an audit snapshot for a product inventory movement.
     *
     * @param  array<string, mixed>  $context
     */
    public function recordProductMovement(
        InventoryMovement $movement,
        int $beforeQuantity,
        int $afterQuantity,
        int $beforeReserved,
        int $afterReserved,
        ?string $reasonCode = null,
        ?string $reasonNotes = null,
        ?Request $request = null,
    ): MovementAuditTrail {
        return $this->createAudit([
            'type' => $movement->type,
            'movement_id' => $movement->id,
            'movement_type' => InventoryMovement::class,
            'stockable_type' => 'App\\Domain\\Product\\Models\\Product',
            'stockable_id' => $movement->product_id,
            'warehouse_id' => $movement->warehouse_id,
            'quantity' => $movement->quantity,
            'before_quantity' => $beforeQuantity,
            'after_quantity' => $afterQuantity,
            'before_reserved' => $beforeReserved,
            'after_reserved' => $afterReserved,
            'reason_code' => $reasonCode,
            'reason_notes' => $reasonNotes,
            'reference_type' => $movement->reference_type,
            'reference_id' => $movement->reference_id,
            'performed_by' => $movement->performed_by,
        ], $request);
    }

    /**
     * Record an audit snapshot for a supply movement.
     *
     * @param  array<string, mixed>  $context
     */
    public function recordSupplyMovement(
        SupplyMovement $movement,
        int $beforeQuantity,
        int $afterQuantity,
        int $beforeReserved,
        int $afterReserved,
        ?string $reasonCode = null,
        ?string $reasonNotes = null,
        ?Request $request = null,
    ): MovementAuditTrail {
        return $this->createAudit([
            'type' => $movement->type,
            'movement_id' => $movement->id,
            'movement_type' => SupplyMovement::class,
            'stockable_type' => 'App\\Domain\\Inventory\\Models\\Supply',
            'stockable_id' => $movement->supply_id,
            'warehouse_id' => $movement->warehouse_id,
            'quantity' => $movement->quantity,
            'before_quantity' => $beforeQuantity,
            'after_quantity' => $afterQuantity,
            'before_reserved' => $beforeReserved,
            'after_reserved' => $afterReserved,
            'reason_code' => $reasonCode,
            'reason_notes' => $reasonNotes,
            'reference_type' => $movement->reference_type,
            'reference_id' => $movement->reference_id,
            'performed_by' => $movement->performed_by,
        ], $request);
    }

    /**
     * Backfill audit rows from existing movements. Useful for one-time seeding.
     */
    public function backfill(): int
    {
        $created = 0;

        InventoryMovement::with('product')
            ->chunkById(100, function ($movements) use (&$created) {
                foreach ($movements as $movement) {
                    if (MovementAuditTrail::where('movement_type_related', InventoryMovement::class)
                        ->where('movement_id', $movement->id)
                        ->exists()) {
                        continue;
                    }

                    $stock = DB::table('product_stocks')
                        ->where('product_id', $movement->product_id)
                        ->where('warehouse_id', $movement->warehouse_id)
                        ->first();

                    $this->recordProductMovement(
                        $movement,
                        beforeQuantity: (int) ($movement->quantity >= 0 ? ($stock->current_stock ?? 0) - $movement->quantity : ($stock->current_stock ?? 0) + abs($movement->quantity)),
                        afterQuantity: (int) ($stock->current_stock ?? 0),
                        beforeReserved: (int) ($stock->reserved_stock ?? 0),
                        afterReserved: (int) ($stock->reserved_stock ?? 0),
                    );
                    $created++;
                }
            });

        return $created;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createAudit(array $data, ?Request $request = null): MovementAuditTrail
    {
        if ($request) {
            $data['ip_address'] = $request->ip();
            $data['user_agent'] = substr($request->userAgent() ?? '', 0, 500);
        }

        return MovementAuditTrail::create($data);
    }
}
