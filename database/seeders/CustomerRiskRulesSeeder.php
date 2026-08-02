<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Shop\Services\CustomerRiskService;
use App\Models\Customer;
use Illuminate\Database\Seeder;

class CustomerRiskRulesSeeder extends Seeder
{
    /**
     * Seed default risk-level rules and apply risk calculation to existing customers.
     */
    public function run(): void
    {
        // Reset any stale blacklist flags without reasons
        Customer::query()
            ->where('is_blacklisted', true)
            ->whereNull('blacklist_reason')
            ->update([
                'blacklist_reason' => 'Pre-existing blacklist (migrated)',
                'blacklisted_at' => now(),
            ]);

        // Ensure all customers have a risk_level
        Customer::query()
            ->whereNull('risk_level')
            ->orWhere('risk_level', '')
            ->update(['risk_level' => 'LOW']);

        // Recalculate risk for customers with order history
        $riskService = app(CustomerRiskService::class);

        Customer::query()
            ->where('total_orders', '>=', 3)
            ->chunk(100, function ($customers) use ($riskService) {
                foreach ($customers as $customer) {
                    $riskService->recalculateRiskLevel($customer);
                }
            });

        // Create a few known blacklist entries for fraud prevention demo
        $blacklistRules = [
            ['phone' => '09000000000', 'name' => 'Fraudulent Customer 1', 'reason' => 'Chargeback fraud — 3 consecutive returns'],
            ['phone' => '09000000001', 'name' => 'Fraudulent Customer 2', 'reason' => 'Fake address — 5 returned orders'],
            ['phone' => '09000000002', 'name' => 'Fraudulent Customer 3', 'reason' => 'Impersonation scam reported'],
        ];

        foreach ($blacklistRules as $rule) {
            $existing = Customer::query()->where('phone', $rule['phone'])->first();

            if ($existing) {
                $existing->update([
                    'is_blacklisted' => true,
                    'blacklist_reason' => $rule['reason'],
                    'blacklisted_at' => now(),
                    'risk_level' => 'BLACKLISTED',
                ]);
            } else {
                Customer::create([
                    'phone' => $rule['phone'],
                    'normalized_phone' => $rule['phone'],
                    'name' => $rule['name'],
                    'is_blacklisted' => true,
                    'blacklist_reason' => $rule['reason'],
                    'blacklisted_at' => now(),
                    'risk_level' => 'BLACKLISTED',
                    'total_orders' => 0,
                    'successful_orders' => 0,
                    'returned_orders' => 0,
                    'success_rate' => 0,
                    'total_revenue' => 0,
                ]);
            }
        }
    }
}
