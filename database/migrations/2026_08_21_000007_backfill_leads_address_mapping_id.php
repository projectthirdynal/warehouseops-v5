<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill leads.address_mapping_id by matching leads.city/state/barangay
 * against the address_mappings table.
 *
 * The leads table uses `state` for province (naming inconsistency with
 * address_mappings which uses `province`). We normalize both sides for
 * matching.
 *
 * Runs in batches to avoid locking on the production dataset.
 */
return new class extends Migration
{
    private const BATCH_SIZE = 5000;

    public function up(): void
    {
        $totalProcessed = 0;
        $offset = 0;

        while (true) {
            $leads = DB::table('leads')
                ->whereNull('address_mapping_id')
                ->select('id', 'city', 'state', 'barangay')
                ->limit(self::BATCH_SIZE)
                ->offset($offset)
                ->get();

            if ($leads->isEmpty()) {
                break;
            }

            $updates = [];
            foreach ($leads as $lead) {
                $mapping = $this->findMapping($lead->state, $lead->city, $lead->barangay);
                if ($mapping) {
                    $updates[$mapping][] = $lead->id;
                }
            }

            foreach ($updates as $mappingId => $leadIds) {
                DB::table('leads')
                    ->whereIn('id', $leadIds)
                    ->update(['address_mapping_id' => $mappingId]);
                $totalProcessed += count($leadIds);
            }

            // Only advance offset by the number of leads that were NOT updated
            // (updated leads will no longer match the whereNull condition)
            $matchedCount = 0;
            foreach ($updates as $leadIds) {
                $matchedCount += count($leadIds);
            }
            $unmatched = $leads->count() - $matchedCount;
            $offset = $unmatched > 0 ? $offset + self::BATCH_SIZE : 0;
        }
    }

    public function down(): void
    {
        DB::table('leads')->whereNotNull('address_mapping_id')->update(['address_mapping_id' => null]);
    }

    private function findMapping(?string $state, ?string $city, ?string $barangay): ?int
    {
        if (! $state && ! $city) {
            return null;
        }

        $provinceNorm = $this->normalize($state);
        $cityNorm = $this->normalize($city);
        $barangayNorm = $this->normalize($barangay);

        // Try exact match on province + city + barangay first
        $query = DB::table('address_mappings');

        if ($provinceNorm) {
            $query->whereRaw('LOWER(province) = ?', [$provinceNorm]);
        }
        if ($cityNorm) {
            $query->whereRaw('LOWER(city_municipality) = ?', [$cityNorm]);
        }
        if ($barangayNorm) {
            $query->whereRaw('LOWER(barangay) = ?', [$barangayNorm]);
        }

        $mapping = $query->first();

        // Fallback: match on province + city only (drop barangay)
        if (! $mapping && $provinceNorm && $cityNorm) {
            $mapping = DB::table('address_mappings')
                ->whereRaw('LOWER(province) = ?', [$provinceNorm])
                ->whereRaw('LOWER(city_municipality) = ?', [$cityNorm])
                ->whereNull('barangay')
                ->first();
        }

        // Fallback: match on city only
        if (! $mapping && $cityNorm) {
            $mapping = DB::table('address_mappings')
                ->whereRaw('LOWER(city_municipality) = ?', [$cityNorm])
                ->first();
        }

        return $mapping?->id;
    }

    private function normalize(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]/', '', $value);

        return $value ?: null;
    }
};
