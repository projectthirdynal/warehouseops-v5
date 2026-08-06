<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\AgentCommission;
use App\Domain\Finance\Models\CommissionRule;
use App\Domain\Finance\Models\CommissionRun;
use App\Domain\Finance\Models\FinancialTransaction;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Models\SiteSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CommissionService
{
    /**
     * Calculate and create a commission record for a delivered order.
     */
    public function createForOrder(Order $order): ?AgentCommission
    {
        if (! $order->assigned_agent_id) {
            return null;
        }

        $rule = CommissionRule::forProduct($order->product_id);
        if (! $rule) {
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
            'agent_id' => $order->assigned_agent_id,
            'order_id' => $order->id,
            'product_id' => $order->product_id,
            'lead_id' => $order->lead_id,
            'waybill_id' => $order->waybill_id,
            'sale_amount' => $saleAmount,
            'commission_rate' => $rateDisplay,
            'commission_amount' => $commissionAmount,
            'status' => 'PENDING',
            'earned_at' => now(),
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
                'status' => 'CANCELLED',
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
                'status' => 'APPROVED',
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
                    'status' => 'PAID',
                    'paid_at' => now(),
                ]);

                FinancialTransaction::create([
                    'type' => 'COMMISSION',
                    'amount' => -$commission->commission_amount,
                    'reference_type' => AgentCommission::class,
                    'reference_id' => $commission->id,
                    'description' => "Commission paid to agent #{$commission->agent_id} for order #{$commission->order_id}",
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
            'total_earned' => (float) $commissions->clone()->sum('commission_amount'),
            'pending' => (float) $commissions->clone()->where('status', 'PENDING')->sum('commission_amount'),
            'approved' => (float) $commissions->clone()->where('status', 'APPROVED')->sum('commission_amount'),
            'paid' => (float) $commissions->clone()->where('status', 'PAID')->sum('commission_amount'),
            'this_month' => (float) $commissions->clone()->whereMonth('earned_at', now()->month)->whereYear('earned_at', now()->year)->sum('commission_amount'),
            'total_orders' => $commissions->clone()->count(),
        ];
    }

    // ── Commission Run Automation ──────────────────────────────────────────

    /**
     * Backfill commissions for delivered orders that are missing commission records.
     */
    public function backfillMissedCommissions(): array
    {
        $deliveredOrders = Order::where('status', OrderStatus::DELIVERED)
            ->whereNotNull('assigned_agent_id')
            ->whereNotIn('id', AgentCommission::select('order_id'))
            ->limit(500)
            ->get();

        $created = 0;
        $skipped = 0;

        foreach ($deliveredOrders as $order) {
            $commission = $this->createForOrder($order);
            if ($commission) {
                $created++;
            } else {
                $skipped++;
            }
        }

        Log::info("Commission backfill: created={$created}, skipped={$skipped}");

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Create a commission run for a given period, grouping all pending commissions.
     */
    public function createRun(
        string $periodType = CommissionRun::PERIOD_MONTHLY,
        ?Carbon $periodStart = null,
        ?Carbon $periodEnd = null,
        ?int $createdBy = null,
    ): CommissionRun {
        return DB::transaction(function () use ($periodType, $periodStart, $periodEnd, $createdBy) {
            $now = now();

            $periodStart = $periodStart ?? match ($periodType) {
                CommissionRun::PERIOD_DAILY => $now->copy()->startOfDay(),
                CommissionRun::PERIOD_WEEKLY => $now->copy()->startOfWeek(),
                CommissionRun::PERIOD_MONTHLY => $now->copy()->startOfMonth(),
                CommissionRun::PERIOD_MANUAL => $now->copy()->startOfMonth(),
            };

            $periodEnd = $periodEnd ?? match ($periodType) {
                CommissionRun::PERIOD_DAILY => $now->copy()->endOfDay(),
                CommissionRun::PERIOD_WEEKLY => $now->copy()->endOfWeek(),
                CommissionRun::PERIOD_MONTHLY => $now->copy()->endOfMonth(),
                CommissionRun::PERIOD_MANUAL => $now->copy()->endOfDay(),
            };

            $name = match ($periodType) {
                CommissionRun::PERIOD_DAILY => 'Daily Commission Run — '.$periodStart->format('M j, Y'),
                CommissionRun::PERIOD_WEEKLY => 'Weekly Commission Run — '.$periodStart->format('M j').' to '.$periodEnd->format('M j, Y'),
                CommissionRun::PERIOD_MONTHLY => 'Monthly Commission Run — '.$periodStart->format('F Y'),
                CommissionRun::PERIOD_MANUAL => 'Manual Commission Run — '.$now->format('M j, Y H:i'),
            };

            // Gather pending commissions earned within the period
            $commissions = AgentCommission::where('status', 'PENDING')
                ->whereNull('commission_run_id')
                ->whereBetween('earned_at', [$periodStart, $periodEnd])
                ->lockForUpdate()
                ->get();

            $totalAmount = (float) $commissions->sum('commission_amount');

            $run = CommissionRun::create([
                'name' => $name,
                'period_type' => $periodType,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'status' => CommissionRun::STATUS_PENDING_APPROVAL,
                'commission_count' => $commissions->count(),
                'total_amount' => $totalAmount,
                'created_by' => $createdBy,
            ]);

            $commissions->each(fn ($c) => $c->update(['commission_run_id' => $run->id]));

            return $run;
        });
    }

    /**
     * Approve a commission run — all its commissions move to APPROVED.
     */
    public function approveRun(CommissionRun $run, int $approvedBy): CommissionRun
    {
        if ($run->status !== CommissionRun::STATUS_PENDING_APPROVAL) {
            throw new \DomainException('Only runs pending approval can be approved.');
        }

        return DB::transaction(function () use ($run, $approvedBy) {
            $run->commissions()
                ->where('status', 'PENDING')
                ->update([
                    'status' => 'APPROVED',
                    'approved_at' => now(),
                ]);

            $run->update([
                'status' => CommissionRun::STATUS_APPROVED,
                'approved_by' => $approvedBy,
                'approved_at' => now(),
            ]);

            return $run->fresh();
        });
    }

    /**
     * Reject a commission run — commissions stay PENDING but are unlinked.
     */
    public function rejectRun(CommissionRun $run, int $rejectedBy, string $reason): CommissionRun
    {
        if ($run->status !== CommissionRun::STATUS_PENDING_APPROVAL) {
            throw new \DomainException('Only runs pending approval can be rejected.');
        }

        return DB::transaction(function () use ($run, $reason) {
            $run->commissions()
                ->where('status', 'PENDING')
                ->update(['commission_run_id' => null]);

            $run->update([
                'status' => CommissionRun::STATUS_REJECTED,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ]);

            return $run->fresh();
        });
    }

    /**
     * Pay out an approved commission run — all APPROVED commissions become PAID.
     */
    public function payRun(CommissionRun $run, int $paidBy): CommissionRun
    {
        if ($run->status !== CommissionRun::STATUS_APPROVED) {
            throw new \DomainException('Only approved runs can be paid.');
        }

        return DB::transaction(function () use ($run, $paidBy) {
            $commissions = $run->commissions()->where('status', 'APPROVED')->get();

            foreach ($commissions as $commission) {
                $commission->update([
                    'status' => 'PAID',
                    'paid_at' => now(),
                ]);

                FinancialTransaction::create([
                    'type' => 'COMMISSION',
                    'amount' => -$commission->commission_amount,
                    'reference_type' => AgentCommission::class,
                    'reference_id' => $commission->id,
                    'description' => "Commission paid to agent #{$commission->agent_id} for order #{$commission->order_id}",
                    'transaction_date' => today(),
                ]);
            }

            $run->update([
                'status' => CommissionRun::STATUS_PAID,
                'paid_by' => $paidBy,
                'paid_at' => now(),
            ]);

            return $run->fresh();
        });
    }

    /**
     * Reject an individual commission within a run.
     */
    public function rejectCommission(int $commissionId, string $reason): void
    {
        $commission = AgentCommission::findOrFail($commissionId);

        if (! in_array($commission->status, ['PENDING', 'APPROVED'])) {
            throw new \DomainException('Only pending or approved commissions can be rejected.');
        }

        $commission->update([
            'status' => 'REJECTED',
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);
    }

    /**
     * Get automation settings.
     */
    public function getSettings(): array
    {
        return [
            'frequency' => SiteSetting::get('commission_run_frequency', 'MONTHLY'),
            'auto_generate_enabled' => (bool) SiteSetting::get('commission_auto_generate', '0'),
            'auto_approve_threshold' => (float) SiteSetting::get('commission_auto_approve_threshold', '0'),
            'min_commission_amount' => (float) SiteSetting::get('commission_min_amount', '0'),
            'require_approval' => (bool) SiteSetting::get('commission_require_approval', '1'),
        ];
    }

    /**
     * Update automation settings.
     */
    public function updateSettings(array $settings): void
    {
        $allowed = [
            'frequency' => 'commission_run_frequency',
            'auto_generate_enabled' => 'commission_auto_generate',
            'auto_approve_threshold' => 'commission_auto_approve_threshold',
            'min_commission_amount' => 'commission_min_amount',
            'require_approval' => 'commission_require_approval',
        ];

        foreach ($allowed as $key => $settingKey) {
            if (array_key_exists($key, $settings)) {
                SiteSetting::set($settingKey, (string) $settings[$key]);
            }
        }
    }

    /**
     * Get run statistics for dashboard.
     */
    public function getRunStats(): array
    {
        $runs = CommissionRun::query();

        return [
            'total_runs' => $runs->clone()->count(),
            'pending_approval' => $runs->clone()->pendingApproval()->count(),
            'approved' => $runs->clone()->approved()->count(),
            'paid' => $runs->clone()->paid()->count(),
            'rejected' => $runs->clone()->where('status', CommissionRun::STATUS_REJECTED)->count(),
            'total_pending_amount' => (float) AgentCommission::where('status', 'PENDING')->sum('commission_amount'),
            'total_approved_amount' => (float) AgentCommission::where('status', 'APPROVED')->sum('commission_amount'),
            'total_paid_amount' => (float) AgentCommission::where('status', 'PAID')->sum('commission_amount'),
            'unassigned_pending' => AgentCommission::where('status', 'PENDING')->whereNull('commission_run_id')->count(),
        ];
    }

    /**
     * Get per-agent breakdown for a run.
     */
    public function getRunAgentBreakdown(CommissionRun $run): Collection
    {
        return $run->commissions()
            ->with('agent:id,name')
            ->selectRaw('agent_id, COUNT(*) as commission_count, SUM(commission_amount) as total_amount')
            ->groupBy('agent_id')
            ->orderByDesc('total_amount')
            ->get()
            ->map(fn ($row) => [
                'agent_id' => $row->agent_id,
                'agent_name' => $row->agent?->name ?? 'Unknown',
                'commission_count' => (int) $row->commission_count,
                'total_amount' => (float) $row->total_amount,
            ]);
    }
}
