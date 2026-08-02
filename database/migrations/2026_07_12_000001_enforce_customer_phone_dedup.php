<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Merge duplicates before adding unique constraint
        $duplicates = DB::table('customers')
            ->select('normalized_phone')
            ->whereNotNull('normalized_phone')
            ->groupBy('normalized_phone')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $dup) {
            $records = DB::table('customers')
                ->where('normalized_phone', $dup->normalized_phone)
                ->orderBy('id')
                ->get();

            $keep = $records->first();
            $rest = $records->skip(1);

            foreach ($rest as $r) {
                // Reassign leads
                DB::table('leads')->where('customer_id', $r->id)->update(['customer_id' => $keep->id]);

                // Reassign orders
                DB::table('orders')->where('customer_id', $r->id)->update(['customer_id' => $keep->id]);

                // Reassign customer identities
                DB::table('customer_identities')->where('customer_id', $r->id)->update(['customer_id' => $keep->id]);

                // Reassign customer addresses (prevent cascade-delete data loss)
                if (Schema::hasTable('customer_addresses')) {
                    DB::table('customer_addresses')->where('customer_id', $r->id)->update(['customer_id' => $keep->id]);
                }

                // Reassign customer notes (prevent cascade-delete data loss)
                if (Schema::hasTable('customer_notes')) {
                    DB::table('customer_notes')->where('customer_id', $r->id)->update(['customer_id' => $keep->id]);
                }

                // Reassign conversations (prevent cascade-delete data loss)
                if (Schema::hasTable('conversations')) {
                    DB::table('conversations')->where('customer_id', $r->id)->update(['customer_id' => $keep->id]);
                }

                // Soft-delete the duplicate
                DB::table('customers')->where('id', $r->id)->delete();
            }
        }

        // Now add the unique constraint
        Schema::table('customers', function (Blueprint $table) {
            $table->unique('normalized_phone', 'customers_normalized_phone_unique');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique('customers_normalized_phone_unique');
        });
    }
};
