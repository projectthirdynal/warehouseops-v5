<?php

declare(strict_types=1);

namespace App\Domain\Shop\Services;

use App\Models\Customer;

class CustomerRiskService
{
    /**
     * Risk level thresholds based on success rate.
     */
    private const RISK_THRESHOLDS = [
        'BLACKLISTED' => -1, // explicit blacklist
        'HIGH'        => 50, // < 50% success rate
        'MEDIUM'      => 70, // < 70% success rate
        'LOW'         => 100, // >= 70% success rate
    ];

    /**
     * Recalculate a customer's risk level based on order history.
     */
    public function recalculateRiskLevel(Customer $customer): Customer
    {
        if ($customer->is_blacklisted) {
            $customer->risk_level = 'BLACKLISTED';
            $customer->save();
            return $customer;
        }

        $total = (int) ($customer->total_orders ?? 0);
        $successful = (int) ($customer->successful_orders ?? 0);
        $returned = (int) ($customer->returned_orders ?? 0);

        // Need at least 3 orders to calculate risk
        if ($total < 3) {
            $customer->risk_level = 'LOW';
            $customer->save();
            return $customer;
        }

        $successRate = $total > 0 ? ($successful / $total) * 100 : 0;
        $returnRate = $total > 0 ? ($returned / $total) * 100 : 0;

        // Auto-blacklist: >60% return rate with 5+ orders
        if ($returnRate >= 60 && $total >= 5) {
            $customer->risk_level = 'BLACKLISTED';
            $customer->is_blacklisted = true;
            $customer->blacklist_reason = 'Auto-blacklisted: excessive returns (' . round($returnRate) . '% return rate)';
            $customer->blacklisted_at = now();
        } elseif ($successRate < self::RISK_THRESHOLDS['HIGH']) {
            $customer->risk_level = 'HIGH';
        } elseif ($successRate < self::RISK_THRESHOLDS['MEDIUM']) {
            $customer->risk_level = 'MEDIUM';
        } else {
            $customer->risk_level = 'LOW';
        }

        $customer->save();
        return $customer;
    }

    /**
     * Manually blacklist a customer.
     */
    public function blacklist(Customer $customer, string $reason): Customer
    {
        $customer->is_blacklisted = true;
        $customer->blacklist_reason = $reason;
        $customer->blacklisted_at = now();
        $customer->risk_level = 'BLACKLISTED';
        $customer->save();

        return $customer;
    }

    /**
     * Remove blacklist status and recalculate risk.
     */
    public function unblacklist(Customer $customer): Customer
    {
        $customer->is_blacklisted = false;
        $customer->blacklist_reason = null;
        $customer->blacklisted_at = null;

        return $this->recalculateRiskLevel($customer);
    }

    /**
     * Manually override a customer's risk level.
     */
    public function overrideRiskLevel(Customer $customer, string $level): Customer
    {
        $customer->risk_level = $level;
        $customer->save();

        return $customer;
    }

    /**
     * Check if a customer should be blocked from ordering.
     */
    public function isBlocked(Customer $customer): bool
    {
        return $customer->is_blacklisted || $customer->risk_level === 'BLACKLISTED';
    }
}
