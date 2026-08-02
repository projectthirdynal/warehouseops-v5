<?php

namespace App\Services;

use App\Domain\Lead\Models\Lead;
use App\Models\AgentProfile;
use App\Models\AgentWorkload;
use App\Models\DistributionQueue;
use App\Models\LeadCycle;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DistributionAnalyticsService
{
    /**
     * Average time from lead creation to first assignment (in minutes).
     */
    public function averageTimeToAssign(\DateTimeInterface $from, \DateTimeInterface $to): float
    {
        $avg = Lead::whereBetween('created_at', [$from, $to])
            ->whereNotNull('assigned_at')
            ->selectRaw('AVG(EXTRACT(EPOCH FROM (assigned_at - created_at)) / 60) as avg_minutes')
            ->first();

        return round((float) ($avg->avg_minutes ?? 0), 2);
    }

    /**
     * Distribution of time-to-assign buckets.
     *
     * @return array<string, int>
     */
    public function timeToAssignDistribution(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $leads = Lead::whereBetween('created_at', [$from, $to])
            ->whereNotNull('assigned_at')
            ->get(['created_at', 'assigned_at']);

        $buckets = ['< 1 min' => 0, '1–5 min' => 0, '5–15 min' => 0, '15–60 min' => 0, '> 60 min' => 0];

        foreach ($leads as $lead) {
            $diff = $lead->created_at->diffInMinutes($lead->assigned_at);
            if ($diff < 1) {
                $buckets['< 1 min']++;
            } elseif ($diff <= 5) {
                $buckets['1–5 min']++;
            } elseif ($diff <= 15) {
                $buckets['5–15 min']++;
            } elseif ($diff <= 60) {
                $buckets['15–60 min']++;
            } else {
                $buckets['> 60 min']++;
            }
        }

        return $buckets;
    }

    /**
     * Agent utilization stats.
     *
     * @return array<int, array{agent_id: int, name: string, active: int, max: int, utilization: float}>
     */
    public function agentUtilization(): array
    {
        $workloads = AgentWorkload::with('agent')->get();

        // Pre-fetch all profiles in one query to avoid N+1
        $agentIds = $workloads->pluck('agent_id')->all();
        $profiles = AgentProfile::whereIn('user_id', $agentIds)
            ->get()
            ->keyBy('user_id');

        return $workloads->map(function (AgentWorkload $w) use ($profiles) {
            $profile = $profiles->get($w->agent_id);
            $max = $profile?->concurrent_lead_cap ?? $profile?->max_active_cycles ?? 10;

            return [
                'agent_id' => $w->agent_id,
                'name' => $w->agent?->name ?? 'Unknown',
                'active' => $w->active_leads_count,
                'max' => $max,
                'utilization' => $max > 0 ? round($w->active_leads_count / $max, 2) : 0,
                'today_assigned' => $w->today_assigned_count,
                'today_converted' => $w->today_converted_count,
            ];
        })->values()->all();
    }

    /**
     * Queue depth over time (last 24 hours, hourly buckets).
     *
     * @return array<string, array{pending: int, assigned: int, failed: int}>
     */
    public function queueDepthOverTime(): array
    {
        $from = now()->subHours(24);

        $items = DistributionQueue::where('created_at', '>=', $from)
            ->selectRaw("DATE_TRUNC('hour', created_at) as hour, status, COUNT(*) as count")
            ->groupBy('hour', 'status')
            ->orderBy('hour')
            ->get();

        $buckets = [];
        for ($i = 0; $i < 24; $i++) {
            $key = now()->subHours(23 - $i)->format('Y-m-d H:00');
            $buckets[$key] = ['pending' => 0, 'assigned' => 0, 'failed' => 0];
        }

        foreach ($items as $item) {
            // DATE_TRUNC returns a plain string; parse to Carbon before formatting
            $key = Carbon::parse($item->hour)->format('Y-m-d H:00');
            if (isset($buckets[$key]) && in_array($item->status, ['pending', 'assigned', 'failed'])) {
                $buckets[$key][$item->status] = (int) $item->count;
            }
        }

        return $buckets;
    }

    /**
     * Current queue snapshot.
     */
    public function queueSnapshot(): array
    {
        return [
            'pending' => DistributionQueue::pending()->count(),
            'assigned' => DistributionQueue::where('status', 'assigned')->count(),
            'failed' => DistributionQueue::where('status', 'failed')->count(),
            'total_today' => DistributionQueue::whereDate('created_at', today())->count(),
        ];
    }

    /**
     * Strategy performance comparison (conversion rate per strategy).
     *
     * @return array<string, array{total: int, converted: int, rate: float}>
     */
    public function strategyPerformance(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        // Join cycles with their matching queue entries to determine strategy used
        $results = DB::table('lead_cycles as c')
            ->join('distribution_queues as q', 'c.lead_id', '=', 'q.lead_id')
            ->join('distribution_rules as r', 'q.rule_id', '=', 'r.id')
            ->whereBetween('c.created_at', [$from, $to])
            ->where('c.status', 'CLOSED')
            ->selectRaw('r.strategy, COUNT(*) as total, SUM(CASE WHEN c.outcome IN (\'SALE\', \'ORDERED\', \'REORDER\') THEN 1 ELSE 0 END) as converted')
            ->groupBy('r.strategy')
            ->get();

        $out = [];
        foreach ($results as $row) {
            $total = (int) $row->total;
            $converted = (int) $row->converted;
            $out[$row->strategy] = [
                'total' => $total,
                'converted' => $converted,
                'rate' => $total > 0 ? round(($converted / $total) * 100, 1) : 0,
            ];
        }

        return $out;
    }

    /**
     * Supervisor alerts: agents near capacity and queue backlog.
     *
     * @return array{capacity_alerts: array<int, array>, backlog_alert: bool, queue_depth: int}
     */
    public function supervisorAlerts(): array
    {
        $capacityAlerts = [];
        $workloads = AgentWorkload::with('agent')->get();

        foreach ($workloads as $w) {
            $profile = AgentProfile::where('user_id', $w->agent_id)->first();
            $max = $profile?->concurrent_lead_cap ?? $profile?->max_active_cycles ?? 10;
            $utilization = $max > 0 ? $w->active_leads_count / $max : 0;

            if ($utilization >= 0.9) {
                $capacityAlerts[] = [
                    'agent_id' => $w->agent_id,
                    'name' => $w->agent?->name ?? 'Unknown',
                    'active' => $w->active_leads_count,
                    'max' => $max,
                    'utilization' => round($utilization, 2),
                ];
            }
        }

        $pending = DistributionQueue::pending()->count();

        return [
            'capacity_alerts' => $capacityAlerts,
            'backlog_alert' => $pending > 50,
            'queue_depth' => $pending,
        ];
    }

    /**
     * Weekly rebalancing report: agents with skewed distribution weight vs results.
     *
     * @return array<int, array{agent_id: int, name: string, weight: float, assigned: int, converted: int, expected_rate: float, actual_rate: float}>
     */
    public function rebalancingReport(): array
    {
        $from = now()->subDays(7);
        $to = now();

        $agents = User::where('role', 'agent')
            ->where('is_active', true)
            ->with('agentProfile')
            ->get();

        $agentIds = $agents->pluck('id')->all();

        // Batch-fetch assigned counts per agent — single query
        $assignedCounts = LeadCycle::whereIn('assigned_agent_id', $agentIds)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('assigned_agent_id, COUNT(*) as cnt')
            ->groupBy('assigned_agent_id')
            ->pluck('cnt', 'assigned_agent_id');

        // Batch-fetch converted counts per agent — single query
        $convertedCounts = LeadCycle::whereIn('assigned_agent_id', $agentIds)
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('outcome', ['SALE', 'ORDERED', 'REORDER'])
            ->selectRaw('assigned_agent_id, COUNT(*) as cnt')
            ->groupBy('assigned_agent_id')
            ->pluck('cnt', 'assigned_agent_id');

        $report = [];
        foreach ($agents as $agent) {
            $profile = $agent->agentProfile;
            $weight = max(0.01, (float) ($profile?->distribution_weight ?? 1.0)); // guard zero division

            $assigned = (int) ($assignedCounts[$agent->id] ?? 0);
            $converted = (int) ($convertedCounts[$agent->id] ?? 0);
            $actualRate = $assigned > 0 ? round(($converted / $assigned) * 100, 1) : 0;
            $expectedRate = round(50 * $weight, 1);

            $report[] = [
                'agent_id' => $agent->id,
                'name' => $agent->name,
                'weight' => $weight,
                'assigned' => $assigned,
                'converted' => $converted,
                'expected_rate' => $expectedRate,
                'actual_rate' => $actualRate,
                'skew' => $expectedRate > 0 ? round(($actualRate / $expectedRate) - 1, 2) : 0,
            ];
        }

        return $report;
    }
}
