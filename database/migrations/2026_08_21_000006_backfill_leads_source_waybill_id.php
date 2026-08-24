<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill leads.source_waybill_id by matching the lead's customer phone to
 * the most recent DELIVERED waybill for that customer.
 *
 * The link is: leads.phone (or customer.phone) → waybills.receiver_phone
 * For each lead without a source_waybill_id, find the most recent delivered
 * waybill with matching receiver_phone and set source_waybill_id.
 *
 * This runs in batches to avoid locking the leads table on the 276K-row
 * production dataset.
 */
return new class extends Migration
{
    private const BATCH_SIZE = 5000;

    public function up(): void
    {
        $totalProcessed = 0;

        // Only process leads that have a phone and no source_waybill_id yet
        $leadIds = DB::table('leads')
            ->whereNull('source_waybill_id')
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->pluck('id', 'phone');

        // Group by normalized phone to batch the waybill lookups
        $phoneGroups = $leadIds->groupBy(fn ($_, $phone) => trim(preg_replace('/[^0-9+]/', '', $phone)));

        foreach ($phoneGroups as $normalizedPhone => $leadIdCollection) {
            if ($normalizedPhone === '' || strlen($normalizedPhone) < 7) {
                continue;
            }

            // Try exact phone match first, then LIKE match
            $waybill = DB::table('waybills')
                ->where('status', 'DELIVERED')
                ->whereNotNull('delivered_at')
                ->where(function ($q) use ($normalizedPhone) {
                    $q->where('receiver_phone', $normalizedPhone)
                        ->orWhere('receiver_phone', 'LIKE', "%{$normalizedPhone}%");
                })
                ->orderBy('delivered_at', 'desc')
                ->first();

            if (! $waybill) {
                continue;
            }

            $leadIdsToUpdate = $leadIdCollection->all();
            foreach (array_chunk($leadIdsToUpdate, self::BATCH_SIZE) as $chunk) {
                DB::table('leads')
                    ->whereIn('id', $chunk)
                    ->update(['source_waybill_id' => $waybill->id]);
                $totalProcessed += count($chunk);
            }
        }
    }

    public function down(): void
    {
        DB::table('leads')->whereNotNull('source_waybill_id')->update(['source_waybill_id' => null]);
    }
};
