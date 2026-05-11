<?php

namespace Database\Seeders;

use App\Domain\Shop\Models\AddressMapping;
use Illuminate\Database\Seeder;

class AddressMappingSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['region' => 'National Capital Region', 'province' => 'Metro Manila', 'city_municipality' => 'Caloocan', 'island_group' => 'Luzon', 'courier_zone' => 'NCR'],
            ['region' => 'National Capital Region', 'province' => 'Metro Manila', 'city_municipality' => 'Las Pinas', 'island_group' => 'Luzon', 'courier_zone' => 'NCR', 'aliases' => ['Las Pinas City']],
            ['region' => 'National Capital Region', 'province' => 'Metro Manila', 'city_municipality' => 'Makati', 'island_group' => 'Luzon', 'courier_zone' => 'NCR'],
            ['region' => 'National Capital Region', 'province' => 'Metro Manila', 'city_municipality' => 'Malabon', 'island_group' => 'Luzon', 'courier_zone' => 'NCR'],
            ['region' => 'National Capital Region', 'province' => 'Metro Manila', 'city_municipality' => 'Mandaluyong', 'island_group' => 'Luzon', 'courier_zone' => 'NCR'],
            ['region' => 'National Capital Region', 'province' => 'Metro Manila', 'city_municipality' => 'Manila', 'island_group' => 'Luzon', 'courier_zone' => 'NCR'],
            ['region' => 'National Capital Region', 'province' => 'Metro Manila', 'city_municipality' => 'Marikina', 'island_group' => 'Luzon', 'courier_zone' => 'NCR'],
            ['region' => 'National Capital Region', 'province' => 'Metro Manila', 'city_municipality' => 'Muntinlupa', 'island_group' => 'Luzon', 'courier_zone' => 'NCR'],
            ['region' => 'National Capital Region', 'province' => 'Metro Manila', 'city_municipality' => 'Navotas', 'island_group' => 'Luzon', 'courier_zone' => 'NCR'],
            ['region' => 'National Capital Region', 'province' => 'Metro Manila', 'city_municipality' => 'Paranaque', 'island_group' => 'Luzon', 'courier_zone' => 'NCR', 'aliases' => ['Paranaque City']],
            ['region' => 'National Capital Region', 'province' => 'Metro Manila', 'city_municipality' => 'Pasay', 'island_group' => 'Luzon', 'courier_zone' => 'NCR'],
            ['region' => 'National Capital Region', 'province' => 'Metro Manila', 'city_municipality' => 'Pasig', 'island_group' => 'Luzon', 'courier_zone' => 'NCR'],
            ['region' => 'National Capital Region', 'province' => 'Metro Manila', 'city_municipality' => 'Quezon City', 'island_group' => 'Luzon', 'courier_zone' => 'NCR'],
            ['region' => 'National Capital Region', 'province' => 'Metro Manila', 'city_municipality' => 'San Juan', 'island_group' => 'Luzon', 'courier_zone' => 'NCR'],
            ['region' => 'National Capital Region', 'province' => 'Metro Manila', 'city_municipality' => 'Taguig', 'island_group' => 'Luzon', 'courier_zone' => 'NCR'],
            ['region' => 'National Capital Region', 'province' => 'Metro Manila', 'city_municipality' => 'Valenzuela', 'island_group' => 'Luzon', 'courier_zone' => 'NCR'],
            ['region' => 'Ilocos Region', 'province' => 'Ilocos Norte', 'city_municipality' => 'Laoag City', 'island_group' => 'Luzon', 'courier_zone' => 'North Luzon'],
            ['region' => 'Ilocos Region', 'province' => 'Ilocos Sur', 'city_municipality' => 'Vigan City', 'island_group' => 'Luzon', 'courier_zone' => 'North Luzon'],
            ['region' => 'Ilocos Region', 'province' => 'La Union', 'city_municipality' => 'San Fernando City', 'island_group' => 'Luzon', 'courier_zone' => 'North Luzon'],
            ['region' => 'Ilocos Region', 'province' => 'Pangasinan', 'city_municipality' => 'Dagupan City', 'island_group' => 'Luzon', 'courier_zone' => 'North Luzon'],
            ['region' => 'Cagayan Valley', 'province' => 'Cagayan', 'city_municipality' => 'Tuguegarao City', 'island_group' => 'Luzon', 'courier_zone' => 'North Luzon'],
            ['region' => 'Cagayan Valley', 'province' => 'Isabela', 'city_municipality' => 'Ilagan City', 'island_group' => 'Luzon', 'courier_zone' => 'North Luzon'],
            ['region' => 'Central Luzon', 'province' => 'Bataan', 'city_municipality' => 'Balanga City', 'island_group' => 'Luzon', 'courier_zone' => 'North Luzon'],
            ['region' => 'Central Luzon', 'province' => 'Bulacan', 'city_municipality' => 'Malolos City', 'island_group' => 'Luzon', 'courier_zone' => 'North Luzon'],
            ['region' => 'Central Luzon', 'province' => 'Nueva Ecija', 'city_municipality' => 'Cabanatuan City', 'island_group' => 'Luzon', 'courier_zone' => 'North Luzon'],
            ['region' => 'Central Luzon', 'province' => 'Pampanga', 'city_municipality' => 'San Fernando City', 'island_group' => 'Luzon', 'courier_zone' => 'North Luzon'],
            ['region' => 'Central Luzon', 'province' => 'Tarlac', 'city_municipality' => 'Tarlac City', 'island_group' => 'Luzon', 'courier_zone' => 'North Luzon'],
            ['region' => 'Central Luzon', 'province' => 'Zambales', 'city_municipality' => 'Olongapo City', 'island_group' => 'Luzon', 'courier_zone' => 'North Luzon'],
            ['region' => 'CALABARZON', 'province' => 'Batangas', 'city_municipality' => 'Batangas City', 'island_group' => 'Luzon', 'courier_zone' => 'South Luzon'],
            ['region' => 'CALABARZON', 'province' => 'Cavite', 'city_municipality' => 'Dasmarinas City', 'island_group' => 'Luzon', 'courier_zone' => 'South Luzon', 'aliases' => ['Dasmarinas']],
            ['region' => 'CALABARZON', 'province' => 'Laguna', 'city_municipality' => 'Calamba City', 'island_group' => 'Luzon', 'courier_zone' => 'South Luzon'],
            ['region' => 'CALABARZON', 'province' => 'Quezon', 'city_municipality' => 'Lucena City', 'island_group' => 'Luzon', 'courier_zone' => 'South Luzon'],
            ['region' => 'CALABARZON', 'province' => 'Rizal', 'city_municipality' => 'Antipolo City', 'island_group' => 'Luzon', 'courier_zone' => 'South Luzon'],
            ['region' => 'MIMAROPA', 'province' => 'Palawan', 'city_municipality' => 'Puerto Princesa City', 'island_group' => 'Luzon', 'courier_zone' => 'South Luzon'],
            ['region' => 'Bicol Region', 'province' => 'Albay', 'city_municipality' => 'Legazpi City', 'island_group' => 'Luzon', 'courier_zone' => 'South Luzon'],
            ['region' => 'Bicol Region', 'province' => 'Camarines Sur', 'city_municipality' => 'Naga City', 'island_group' => 'Luzon', 'courier_zone' => 'South Luzon'],
            ['region' => 'Western Visayas', 'province' => 'Aklan', 'city_municipality' => 'Kalibo', 'island_group' => 'Visayas', 'courier_zone' => 'Visayas'],
            ['region' => 'Western Visayas', 'province' => 'Iloilo', 'city_municipality' => 'Iloilo City', 'island_group' => 'Visayas', 'courier_zone' => 'Visayas'],
            ['region' => 'Central Visayas', 'province' => 'Cebu', 'city_municipality' => 'Cebu City', 'island_group' => 'Visayas', 'courier_zone' => 'Visayas'],
            ['region' => 'Central Visayas', 'province' => 'Bohol', 'city_municipality' => 'Tagbilaran City', 'island_group' => 'Visayas', 'courier_zone' => 'Visayas'],
            ['region' => 'Eastern Visayas', 'province' => 'Leyte', 'city_municipality' => 'Tacloban City', 'island_group' => 'Visayas', 'courier_zone' => 'Visayas'],
            ['region' => 'Zamboanga Peninsula', 'province' => 'Zamboanga del Sur', 'city_municipality' => 'Zamboanga City', 'island_group' => 'Mindanao', 'courier_zone' => 'Mindanao'],
            ['region' => 'Northern Mindanao', 'province' => 'Misamis Oriental', 'city_municipality' => 'Cagayan de Oro City', 'island_group' => 'Mindanao', 'courier_zone' => 'Mindanao'],
            ['region' => 'Davao Region', 'province' => 'Davao del Sur', 'city_municipality' => 'Davao City', 'island_group' => 'Mindanao', 'courier_zone' => 'Mindanao'],
            ['region' => 'SOCCSKSARGEN', 'province' => 'South Cotabato', 'city_municipality' => 'General Santos City', 'island_group' => 'Mindanao', 'courier_zone' => 'Mindanao'],
            ['region' => 'Caraga', 'province' => 'Agusan del Norte', 'city_municipality' => 'Butuan City', 'island_group' => 'Mindanao', 'courier_zone' => 'Mindanao'],
            ['region' => 'BARMM', 'province' => 'Maguindanao del Norte', 'city_municipality' => 'Cotabato City', 'island_group' => 'Mindanao', 'courier_zone' => 'Mindanao'],
        ];

        foreach ($rows as $row) {
            AddressMapping::query()->updateOrCreate(
                [
                    'country' => 'PH',
                    'province' => $row['province'],
                    'city_municipality' => $row['city_municipality'],
                    'barangay' => null,
                ],
                [
                    'region' => $row['region'],
                    'island_group' => $row['island_group'],
                    'courier_zone' => $row['courier_zone'],
                    'aliases' => $row['aliases'] ?? null,
                ]
            );
        }
    }
}
