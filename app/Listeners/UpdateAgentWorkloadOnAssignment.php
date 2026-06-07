<?php

namespace App\Listeners;

use App\Events\LeadAssigned;
use App\Models\AgentWorkload;

class UpdateAgentWorkloadOnAssignment
{
    public function handle(LeadAssigned $event): void
    {
        $workload = AgentWorkload::firstOrCreate(
            ['agent_id' => $event->agent->id],
            ['active_leads_count' => 0, 'today_assigned_count' => 0, 'today_converted_count' => 0]
        );

        $workload->recordAssignment();
    }
}
