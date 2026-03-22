<?php

namespace App\Services;

use App\Domain\Lead\Models\Lead;
use App\Models\LeadCycle;
use App\Models\User;

class CallTrackingService
{
    public function __construct(
        private LeadAuditService $auditService
    ) {}

    public function initiateCall(Lead $lead, LeadCycle $cycle, User $agent): string
    {
        // Record the call attempt
        $cycle->increment('call_count');
        $cycle->update(['last_call_at' => now()]);

        // Update lead's last_called_at
        $lead->update([
            'last_called_at' => now(),
            'call_attempts' => $lead->call_attempts + 1,
        ]);

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
        return 'sip:' . $lead->phone;
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
}
