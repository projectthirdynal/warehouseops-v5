<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CourierProviderSeeder extends Seeder
{
    public function run(): void
    {
        $providers = [
            [
                'code'           => 'JNT',
                'name'           => 'J&T Express Philippines',
                'is_active'      => true,
                'api_endpoint'   => 'https://openapi.jtexpress.ph/api',
                'config'         => json_encode(['max_batch_tracking' => 50, 'rate_limit_per_min' => 60]),
                'webhook_secret' => null,
                'created_at'     => now(),
                'updated_at'     => now(),
            ],
            [
                'code'           => 'FLASH',
                'name'           => 'Flash Express Philippines',
                'is_active'      => true,
                'api_endpoint'   => 'https://open.flashexpress.ph/open/v3',
                'config'         => json_encode(['rate_limit_per_min' => 100]),
                'webhook_secret' => null,
                'created_at'     => now(),
                'updated_at'     => now(),
            ],
        ];

        foreach ($providers as $provider) {
            DB::table('courier_providers')->updateOrInsert(
                ['code' => $provider['code']],
                $provider
            );
        }
    }
}
