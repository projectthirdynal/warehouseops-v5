<?php

namespace App\Services;

use Modules\Leads\Models\Lead;
use App\Models\LeadCycle;
use App\Models\User;
use Illuminate\Support\Carbon;

class CallTrackingService
{
    public function __construct(
        private LeadAuditService $auditService
    ) {}

    public function initiateCall(Lead $lead, LeadCycle $cycle, User $agent): string
    {
        if (empty($lead->phone)) {
            throw new \InvalidArgumentException('Lead phone number is required');
        }

        // Record the call attempt
        $cycle->increment('call_count');
        $cycle->update(['last_call_at' => now()]);

        // Update lead's last_called_at
        $lead->increment('call_attempts');
        $lead->update(['last_called_at' => now()]);

        // Log to audit trail
        $this->auditService->log(
            lead: $lead,
            action: 'CALL_INITIATED',
            user: $agent,
            cycle: $cycle,
            metadata: [
                'call_number' => $cycle->call_count,
                'total_attempts' => $lead->call_attempts,
            ]
        );

        // Return SIP link for MicroSIP
        $sanitizedPhone = preg_replace('/[^0-9+]/', '', $lead->phone);

        return 'sip:'.$sanitizedPhone;
    }

    public function getAgentCallStats(User $agent, ?string $period = 'today'): array
    {
        $query = LeadCycle::where('assigned_agent_id', $agent->id);

        if ($period === 'today') {
            $query->whereDate('last_call_at', today());
        }

        return [
            'total_calls' => (clone $query)->sum('call_count'),
            'leads_called' => (clone $query)->whereNotNull('last_call_at')->count(),
        ];
    }

    /**
     * Leads & Distribution Engine — Agent Management Phase 1 C1: Performance Dashboard.
     *
     * Real-time per-agent performance for the supervisor monitoring dashboard:
     * calls made, conversion rate, and average handle time, scoped to a period.
     *
     * @return array<int, array{id: int, name: string, is_available: bool, calls: int, leads: int, sales: int, conversion_rate: float, avg_handle_time_hours: float}>
     */
    public function getTeamPerformance(string $period = 'today'): array
    {
        [$from, $to] = $this->resolvePeriod($period);

        $agents = User::where('role', 'agent')
            ->where('is_active', true)
            ->with('agentProfile')
            ->get();

        if ($agents->isEmpty()) {
            return [];
        }

        $cycles = LeadCycle::whereIn('assigned_agent_id', $agents->pluck('id'))
            ->whereBetween('last_call_at', [$from, $to])
            ->get()
            ->groupBy('assigned_agent_id');

        return $agents->map(function (User $agent) use ($cycles) {
            $agentCycles = $cycles->get($agent->id, collect());
            $closed = $agentCycles->whereNotNull('outcome');
            $sales = $closed->where('outcome', 'ORDERED')->count();
            $handled = $closed->filter(fn (LeadCycle $c) => $c->opened_at && $c->closed_at);

            return [
                'id' => $agent->id,
                'name' => $agent->name,
                'is_available' => (bool) ($agent->agentProfile?->is_available ?? false),
                'calls' => (int) $agentCycles->sum('call_count'),
                'leads' => $agentCycles->count(),
                'sales' => $sales,
                'conversion_rate' => $agentCycles->count() > 0
                    ? round(($sales / $agentCycles->count()) * 100, 1)
                    : 0.0,
                'avg_handle_time_hours' => $handled->isNotEmpty()
                    ? round($handled->avg(fn (LeadCycle $c) => $c->opened_at->diffInMinutes($c->closed_at) / 60), 2)
                    : 0.0,
            ];
        })
            ->sortByDesc('calls')
            ->values()
            ->all();
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolvePeriod(string $period): array
    {
        return match ($period) {
            'yesterday' => [today()->subDay(), today()->subDay()->endOfDay()],
            'week' => [now()->startOfWeek(), now()],
            'month' => [now()->startOfMonth(), now()],
            default => [today(), now()],
        };
    }
}
