<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Models\Supply;
use Illuminate\Support\Facades\DB;

class StockStatusService
{
    public const MOVING_DAYS     = 30;
    public const NON_MOVING_DAYS = 90;

    public function recompute(Supply $supply): void
    {
        if ($supply->stock_status_override) {
            return;
        }

        $supply->stock_status = $this->classify($supply);
        $supply->saveQuietly();
    }

    public function recomputeAll(): int
    {
        $updated = 0;

        Supply::withoutTrashed()
            ->where('stock_status_override', false)
            ->chunkById(200, function ($supplies) use (&$updated): void {
                foreach ($supplies as $supply) {
                    $newStatus = $this->classify($supply);

                    if ($supply->stock_status !== $newStatus) {
                        DB::table('supplies')
                            ->where('id', $supply->id)
                            ->update(['stock_status' => $newStatus, 'updated_at' => now()]);
                        $updated++;
                    }
                }
            });

        return $updated;
    }

    private function classify(Supply $supply): string
    {
        $lastMovement = DB::table('supply_stocks')
            ->where('supply_id', $supply->id)
            ->max('last_movement_at');

        // No movement record yet — use supply creation date as the baseline.
        // A brand-new material should never auto-classify as DEAD.
        if ($lastMovement === null) {
            $daysSinceCreated = now()->diffInDays($supply->created_at);

            if ($daysSinceCreated < self::NON_MOVING_DAYS) {
                return Supply::STATUS_MOVING;
            }

            $totalStock = DB::table('supply_stocks')
                ->where('supply_id', $supply->id)
                ->sum('current_stock');

            return $totalStock > 0 ? Supply::STATUS_DEAD : Supply::STATUS_MOVING;
        }

        $daysSince = now()->diffInDays($lastMovement);

        if ($daysSince < self::MOVING_DAYS) {
            return Supply::STATUS_MOVING;
        }

        if ($daysSince < self::NON_MOVING_DAYS) {
            return Supply::STATUS_NON_MOVING;
        }

        return Supply::STATUS_DEAD;
    }
}
