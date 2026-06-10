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

                    DB::table('supplies')
                        ->where('id', $supply->id)
                        ->update(['stock_status' => $newStatus, 'updated_at' => now()]);

                    if ($supply->stock_status !== $newStatus) {
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

        // Use MAX(last_movement_at, created_at) as effective activity date.
        // This means a brand-new material always starts as MOVING regardless
        // of whether it has a stock record yet.
        $effectiveDate = $lastMovement !== null && $lastMovement > $supply->created_at
            ? $lastMovement
            : $supply->created_at;

        $daysSince = now()->diffInDays($effectiveDate);

        if ($daysSince < self::MOVING_DAYS) {
            return Supply::STATUS_MOVING;
        }

        if ($daysSince < self::NON_MOVING_DAYS) {
            return Supply::STATUS_NON_MOVING;
        }

        return Supply::STATUS_DEAD;
    }
}
