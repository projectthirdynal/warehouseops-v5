<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadCycle;
use App\Models\LeadPoolAudit;
use App\Models\User;
use Illuminate\Support\Facades\Request;

class LeadAuditService
{
    public function log(
        Lead $lead,
        string $action,
        ?User $user = null,
        ?LeadCycle $cycle = null,
        ?string $oldValue = null,
        ?string $newValue = null,
        ?array $metadata = null,
    ): LeadPoolAudit {
        return LeadPoolAudit::create([
            'lead_id' => $lead->id,
            'lead_cycle_id' => $cycle?->id,
            'user_id' => $user?->id ?? auth()->id(),
            'action' => $action,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'metadata' => $metadata,
            'ip_address' => Request::ip(),
        ]);
    }

    public function getLeadHistory(Lead $lead, int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        return LeadPoolAudit::where('lead_id', $lead->id)
            ->with('user')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }
}
