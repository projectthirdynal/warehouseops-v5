<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $existing = DB::table('units_of_measure')
            ->whereIn('abbreviation', ['roll', 'sack'])
            ->pluck('abbreviation')
            ->all();

        $toInsert = [];

        if (! in_array('roll', $existing)) {
            $toInsert[] = [
                'name' => 'Roll',
                'abbreviation' => 'roll',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (! in_array('sack', $existing)) {
            $toInsert[] = [
                'name' => 'Sack',
                'abbreviation' => 'sack',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (! empty($toInsert)) {
            DB::table('units_of_measure')->insert($toInsert);
        }
    }

    public function down(): void
    {
        DB::table('units_of_measure')
            ->whereIn('abbreviation', ['roll', 'sack'])
            ->delete();
    }
};
