<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Modules\Shop\Services\CustomerAddressService;
use App\Models\Customer;
use Illuminate\Console\Command;

class BackfillCustomerAddresses extends Command
{
    protected $signature = 'customers:backfill-addresses {--chunk=200}';

    protected $description = 'Backfill customer address history from current canonical_address';

    public function handle(CustomerAddressService $service): int
    {
        $chunk = (int) $this->option('chunk');
        $total = Customer::query()->count();

        $this->info("Backfilling addresses for {$total} customers...");

        $processed = 0;
        Customer::query()->whereNotNull('canonical_address')->chunkById($chunk, function ($customers) use ($service, &$processed, $total) {
            foreach ($customers as $customer) {
                $service->record($customer, [
                    'label' => 'Default',
                    'canonical_address' => $customer->canonical_address,
                    'landmark' => $customer->landmark,
                    'barangay' => $customer->barangay,
                    'city_municipality' => $customer->city_municipality,
                    'province' => $customer->province,
                    'region' => $customer->region,
                ], true, 'backfill');
                $processed++;
            }
            $this->info("Processed {$processed}/{$total}");
        });

        $this->info('Done.');

        return self::SUCCESS;
    }
}
