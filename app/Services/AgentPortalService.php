<?php

namespace App\Services;

use Modules\Finance\Models\AgentCommission;
use Modules\Finance\Services\CommissionService;
use App\Models\AgentWorkload;
use App\Models\LeadCycle;
use App\Models\User;

class AgentPortalService
{
    public function __construct(
        private CommissionService $commissionService
    ) {}

    /**
     * Get the full portal dashboard data for an agent.
     */
    public function getDashboardData(User $agent): array
    {
        return [
            'earnings' => $this->getEarningsSummary($agent->id),
            'recent_commissions' => $this->getRecentCommissions($agent->id),
            'lead_history' => $this->getLeadHistory($agent->id),
            'leaderboard' => $this->getLeaderboard($agent->id),
            'workload' => $this->getWorkload($agent->id),
        ];
    }

    /**
     * Earnings summary from CommissionService.
     */
    public function getEarningsSummary(int $agentId): array
    {
        $summary = $this->commissionService->getAgentSummary($agentId);

        $lastMonth = (float) AgentCommission::where('agent_id', $agentId)
            ->whereMonth('earned_at', now()->subMonth()->month)
            ->whereYear('earned_at', now()->subMonth()->year)
            ->sum('commission_amount');

        $summary['last_month'] = $lastMonth;
        $summary['month_change'] = $summary['this_month'] - $lastMonth;

        return $summary;
    }

    /**
     * Recent commission records (last 20).
     */
    public function getRecentCommissions(int $agentId): array
    {
        return AgentCommission::where('agent_id', $agentId)
            ->with(['order:id,order_number,total_amount', 'product:id,name'])
            ->orderByDesc('earned_at')
            ->limit(20)
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'order_number' => $c->order?->order_number ?? "Order #{$c->order_id}",
                'product_name' => $c->product?->name ?? 'N/A',
                'sale_amount' => (float) $c->sale_amount,
                'commission_amount' => (float) $c->commission_amount,
                'commission_rate' => (float) $c->commission_rate,
                'status' => $c->status,
                'earned_at' => $c->earned_at?->toIso8601String(),
                'paid_at' => $c->paid_at?->toIso8601String(),
            ])
            ->toArray();
    }

    /**
     * Recent lead cycle history (last 30 closed cycles).
     */
    public function getLeadHistory(int $agentId): array
    {
        return LeadCycle::where('assigned_agent_id', $agentId)
            ->whereNotNull('closed_at')
            ->with(['lead:id,name,product_name,amount,city,state'])
            ->orderByDesc('closed_at')
            ->limit(30)
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'cycle_number' => $c->cycle_number,
                'lead_name' => $c->lead?->name ?? 'Unknown',
                'product' => $c->lead?->product_name ?? 'N/A',
                'amount' => (float) ($c->lead?->amount ?? 0),
                'outcome' => $c->outcome,
                'status' => $c->status,
                'call_count' => $c->call_count,
                'opened_at' => $c->opened_at?->toIso8601String(),
                'closed_at' => $c->closed_at?->toIso8601String(),
                'handle_time_hours' => $c->opened_at && $c->closed_at
                    ? round($c->opened_at->diffInMinutes($c->closed_at) / 60, 2)
                    : 0,
            ])
            ->toArray();
    }

    /**
     * Monthly leaderboard — top agents by sales count and revenue.
     * Highlights the current agent's rank.
     */
    public function getLeaderboard(int $agentId): array
    {
        $monthStart = now()->startOfMonth();

        $rows = LeadCycle::whereNotNull('closed_at')
            ->where('closed_at', '>=', $monthStart)
            ->where('outcome', 'ORDERED')
            ->selectRaw(
                'assigned_agent_id, COUNT(*) as sales_count, SUM(COALESCE((SELECT amount FROM leads WHERE leads.id = lead_cycles.lead_id), 0)) as revenue'
            )
            ->groupBy('assigned_agent_id')
            ->orderByDesc('sales_count')
            ->limit(10)
            ->get();

        $agentIds = $rows->pluck('assigned_agent_id')->unique()->filter();
        $agents = User::whereIn('id', $agentIds)->pluck('name', 'id');

        $items = $rows->map(fn ($r, $i) => [
            'rank' => $i + 1,
            'agent_id' => $r->assigned_agent_id,
            'agent_name' => $agents[$r->assigned_agent_id] ?? 'Unknown',
            'sales_count' => (int) $r->sales_count,
            'revenue' => (float) $r->revenue,
            'is_me' => $r->assigned_agent_id === $agentId,
        ])->toArray();

        $myRank = null;
        foreach ($items as $item) {
            if ($item['is_me']) {
                $myRank = $item['rank'];
                break;
            }
        }

        // If agent not in top 10, find their actual rank
        if ($myRank === null) {
            $myStats = LeadCycle::where('assigned_agent_id', $agentId)
                ->whereNotNull('closed_at')
                ->where('closed_at', '>=', $monthStart)
                ->where('outcome', 'ORDERED')
                ->count();

            if ($myStats > 0) {
                $higherCount = LeadCycle::whereNotNull('closed_at')
                    ->where('closed_at', '>=', $monthStart)
                    ->where('outcome', 'ORDERED')
                    ->selectRaw('assigned_agent_id, COUNT(*) as cnt')
                    ->groupBy('assigned_agent_id')
                    ->having('cnt', '>', $myStats)
                    ->count();
                $myRank = $higherCount + 1;
            }
        }

        return [
            'items' => $items,
            'my_rank' => $myRank,
            'period' => 'month',
        ];
    }

    /**
     * Current workload stats for the agent.
     */
    public function getWorkload(int $agentId): array
    {
        $workload = AgentWorkload::find($agentId);

        if (! $workload) {
            return [
                'active_leads' => 0,
                'today_assigned' => 0,
                'today_converted' => 0,
            ];
        }

        return [
            'active_leads' => $workload->active_leads_count,
            'today_assigned' => $workload->today_assigned_count,
            'today_converted' => $workload->today_converted_count,
        ];
    }
}
