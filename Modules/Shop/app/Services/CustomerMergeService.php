<?php

declare(strict_types=1);

namespace Modules\Shop\Services;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;

class CustomerMergeService
{
    /**
     * Merge $source into $target — reassign all related records, then soft-delete $source.
     */
    public function merge(Customer $target, Customer $source): Customer
    {
        if ($target->id === $source->id) {
            return $target;
        }

        DB::transaction(function () use ($target, $source) {
            // Reassign leads
            DB::table('leads')->where('customer_id', $source->id)->update(['customer_id' => $target->id]);

            // Reassign orders
            DB::table('orders')->where('customer_id', $source->id)->update(['customer_id' => $target->id]);

            // Reassign customer identities
            DB::table('customer_identities')->where('customer_id', $source->id)->update(['customer_id' => $target->id]);

            // Reassign conversations
            DB::table('conversations')->where('customer_id', $source->id)->update(['customer_id' => $target->id]);

            DB::table('customer_addresses')->where('customer_id', $source->id)->update(['customer_id' => $target->id]);
            DB::table('customer_notes')->where('customer_id', $source->id)->update(['customer_id' => $target->id]);

            // Aggregate counters onto target
            $target->total_orders = ($target->total_orders ?? 0) + ($source->total_orders ?? 0);
            $target->successful_orders = ($target->successful_orders ?? 0) + ($source->successful_orders ?? 0);
            $target->returned_orders = ($target->returned_orders ?? 0) + ($source->returned_orders ?? 0);
            $target->total_revenue = (float) ($target->total_revenue ?? 0) + (float) ($source->total_revenue ?? 0);
            $target->success_rate = $target->total_orders > 0
                ? round(($target->successful_orders / $target->total_orders) * 100, 2)
                : 0;
            $target->average_order_value = $target->total_orders > 0
                ? round($target->total_revenue / $target->total_orders, 2)
                : 0;

            // Keep the worse risk level
            $riskOrder = ['LOW' => 0, 'MEDIUM' => 1, 'HIGH' => 2, 'BLACKLISTED' => 3];
            if (($riskOrder[$source->risk_level ?? 'LOW'] ?? 0) > ($riskOrder[$target->risk_level ?? 'LOW'] ?? 0)) {
                $target->risk_level = $source->risk_level;
                $target->is_blacklisted = $source->is_blacklisted;
                $target->blacklist_reason = $source->blacklist_reason;
                $target->blacklisted_at = $source->blacklisted_at;
            }

            // Keep the earlier last_order_date
            if ($source->last_order_date && (! $target->last_order_date || $source->last_order_date < $target->last_order_date)) {
                $target->last_order_date = $source->last_order_date;
            }

            // Fill missing fields on target from source
            foreach (['phone', 'facebook_name', 'canonical_address', 'landmark', 'barangay', 'city_municipality', 'province', 'region'] as $field) {
                if (empty($target->{$field}) && ! empty($source->{$field})) {
                    $target->{$field} = $source->{$field};
                }
            }

            $target->save();
            $source->delete();
        });

        return $target->fresh();
    }

    /**
     * Find and merge all duplicate customers by normalized_phone.
     *
     * @return int Number of duplicates merged.
     */
    public function mergeAllDuplicates(): int
    {
        $duplicates = Customer::query()
            ->select('normalized_phone')
            ->whereNotNull('normalized_phone')
            ->groupBy('normalized_phone')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('normalized_phone');

        $merged = 0;

        foreach ($duplicates as $phone) {
            $records = Customer::query()
                ->where('normalized_phone', $phone)
                ->orderBy('id')
                ->get();

            $target = $records->first();

            foreach ($records->skip(1) as $source) {
                $this->merge($target, $source);
                $merged++;
            }
        }

        return $merged;
    }
}
