<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\AgentCommission;
use App\Domain\Finance\Models\CommissionRule;
use App\Domain\Finance\Models\FinancialTransaction;
use App\Domain\Order\Models\Order;
use Illuminate\Support\Facades\DB;

class CommissionService
{
    /**
     * Calculate and create a commission record for a delivered order.
     */
    public function createForOrder(Order $order): ?AgentCommission
    {
        if (!$order->assigned_agent_id) {
            return null;
        }

        $rule = CommissionRule::forProduct($order->product_id);
        if (!$rule) {
            return null;
        }

        $saleAmount = (float) $order->total_amount;
        $commissionAmount = $rule->calculate($saleAmount);

        if ($commissionAmount <= 0) {
            return null;
        }

        $rateDisplay = $rule->rate_type === 'PERCENTAGE'
            ? $rule->rate_value / 100
            : $rule->rate_value;

        return AgentCommission::create([
            'agent_id'          => $order->assigned_agent_id,
            'order_id'          => $order->id,
            'product_id'        => $order->product_id,
            'lead_id'           => $order->lead_id,
            'waybill_id'        => $order->waybill_id,
            'sale_amount'       => $saleAmount,
            'commission_rate'   => $rateDisplay,
            'commission_amount' => $commissionAmount,
            'status'            => 'PENDING',
            'earned_at'         => now(),
        ]);
    }

    /**
     * Cancel commission for a returned/cancelled order.
     */
    public function cancelForOrder(Order $order): void
    {
        AgentCommission::where('order_id', $order->id)
            ->whereIn('status', ['PENDING', 'APPROVED'])
            ->update([
                'status'       => 'CANCELLED',
                'cancelled_at' => now(),
            ]);
    }

    /**
     * Approve pending commissions (bulk).
     */
    public function approveCommissions(array $commissionIds): int
    {
        return AgentCommission::whereIn('id', $commissionIds)
            ->where('status', 'PENDING')
            ->update([
                'status'      => 'APPROVED',
                'approved_at' => now(),
            ]);
    }

    /**
     * Mark approved commissions as paid.
     */
    public function markAsPaid(array $commissionIds): int
    {
        return DB::transaction(function () use ($commissionIds) {
            $commissions = AgentCommission::whereIn('id', $commissionIds)
                ->where('status', 'APPROVED')
                ->get();

            foreach ($commissions as $commission) {
                $commission->update([
                    'status'  => 'PAID',
                    'paid_at' => now(),
                ]);

                FinancialTransaction::create([
                    'type'             => 'COMMISSION',
                    'amount'           => -$commission->commission_amount,
                    'reference_type'   => AgentCommission::class,
                    'reference_id'     => $commission->id,
                    'description'      => "Commission paid to agent #{$commission->agent_id} for order #{$commission->order_id}",
                    'transaction_date' => today(),
                ]);
            }

            return $commissions->count();
        });
    }

    /**
     * Get agent earnings summary.
     */
    public function getAgentSummary(int $agentId): array
    {
        $commissions = AgentCommission::where('agent_id', $agentId);

        return [
            'total_earned'   => (float) $commissions->clone()->sum('commission_amount'),
            'pending'        => (float) $commissions->clone()->where('status', 'PENDING')->sum('commission_amount'),
            'approved'       => (float) $commissions->clone()->where('status', 'APPROVED')->sum('commission_amount'),
            'paid'           => (float) $commissions->clone()->where('status', 'PAID')->sum('commission_amount'),
            'this_month'     => (float) $commissions->clone()->whereMonth('earned_at', now()->month)->whereYear('earned_at', now()->year)->sum('commission_amount'),
            'total_orders'   => $commissions->clone()->count(),
        ];
    }
}
