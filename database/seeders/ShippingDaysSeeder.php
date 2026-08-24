<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Courier\Models\ShippingDay;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class ShippingDaysSeeder extends Seeder
{
    public function run(): void
    {
        $csvPath = database_path('data/shipping_days.csv');

        if (! File::exists($csvPath)) {
            $this->command->warn('shipping_days.csv not found at '.$csvPath);

            return;
        }

        // Clear existing data
        ShippingDay::truncate();

        $handle = fopen($csvPath, 'r');
        $header = fgetcsv($handle); // Skip header row

        $batch = [];
        $batchSize = 1000;
        $count = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 4 || empty($row[0]) || empty($row[3])) {
                continue;
            }

            $batch[] = [
                'province' => trim($row[0]),
                'city' => trim($row[1]),
                'barangay' => ! empty($row[2]) ? trim($row[2]) : null,
                'shipping_days' => (int) $row[3],
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $count++;

            if (count($batch) >= $batchSize) {
                ShippingDay::insert($batch);
                $batch = [];
            }
        }

        // Insert remaining
        if (! empty($batch)) {
            ShippingDay::insert($batch);
        }

        fclose($handle);

        $this->command->info("Imported {$count} shipping day records.");
    }
}
