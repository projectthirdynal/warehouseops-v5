<?php

namespace App\Services;

use App\Domain\Lead\Enums\LeadOutcome;
use App\Domain\Lead\Models\Lead;
use App\Models\LeadCycle;
use App\Models\RecyclingRule;
use App\Models\User;

class LeadRecyclingService
{
    public function __construct(
        private LeadPoolService $poolService,
        private LeadAuditService $auditService
    ) {}

    public function processOutcome(
        Lead $lead,
        LeadCycle $cycle,
        LeadOutcome $outcome,
        User $agent,
        ?string $remarks = null,
        ?\DateTimeInterface $callbackAt = null
    ): void {
        // Handle CALLBACK - keep with agent, set callback time
        if ($outcome === LeadOutcome::CALLBACK) {
            if ($callbackAt) {
                // Keep assigned, cycle stays active
                $cycle->update([
                    'status' => 'ACTIVE', // Don't close cycle
                    'callback_at' => $callbackAt,
                    'callback_notes' => $remarks,
                    'outcome' => null, // Clear outcome until callback completed
                ]);

                $this->auditService->log(
                    lead: $lead,
                    action: 'CALLBACK_SCHEDULED',
                    user: $agent,
                    cycle: $cycle,
                    metadata: ['callback_at' => $callbackAt->format('c'), 'notes' => $remarks]
                );
            }
            return; // Don't change pool status
        }

        // Close the cycle
        $cycle->update([
            'status' => 'CLOSED',
            'outcome' => $outcome->value,
            'closed_at' => now(),
        ]);

        // Log outcome
        $this->auditService->log(
            lead: $lead,
            action: 'OUTCOME_SET',
            user: $agent,
            cycle: $cycle,
            newValue: $outcome->value,
            metadata: ['remarks' => $remarks]
        );

        // Get recycling rule
        $rule = RecyclingRule::forOutcome($outcome);

        if (! $rule) {
            // No rule, just mark as available
            $this->poolService->markAsAvailable($lead);

            return;
        }

        // Check if at max cycles or should exhaust
        if ($lead->total_cycles >= $rule->max_cycles || $rule->shouldExhaust()) {
            $this->poolService->markAsExhausted($lead);

            return;
        }

        // Move to cooldown or available
        if ($rule->cooldown_hours > 0) {
            $this->poolService->markAsCooldown($lead, $rule->cooldown_hours);
        } else {
            $this->poolService->markAsAvailable($lead);
        }
    }

    public function processExpiredCooldowns(): int
    {
        $leads = Lead::cooldownExpired()->get();
        $processed = 0;

        foreach ($leads as $lead) {
            // Check if lead has hit max cycles
            $lastCycle = $lead->cycles()->latest()->first();
            $outcome = $lastCycle ? LeadOutcome::tryFrom($lastCycle->outcome) : null;

            if ($outcome) {
                $rule = RecyclingRule::forOutcome($outcome);
                if ($rule && $lead->total_cycles >= $rule->max_cycles) {
                    $this->poolService->markAsExhausted($lead);
                    $processed++;

                    continue;
                }
            }

            $this->poolService->markAsAvailable($lead);
            $processed++;
        }

        return $processed;
    }

    public function processExpiredCallbacks(): int
    {
        $expired = LeadCycle::where('status', 'ACTIVE')
            ->whereNotNull('callback_at')
            ->where('callback_at', '<', now()->subHours(24))
            ->where('call_count', '>', 0)
            ->get();

        $processed = 0;

        foreach ($expired as $cycle) {
            $lead = $cycle->lead;
            $rule = RecyclingRule::forOutcome(LeadOutcome::NO_ANSWER);

            if ($lead->total_cycles >= ($rule->max_cycles ?? 5)) {
                $this->poolService->markAsExhausted($lead);
            } else {
                $this->poolService->markAsCooldown($lead, $rule->cooldown_hours ?? 24);
            }

            $cycle->update(['status' => 'CLOSED', 'outcome' => 'CALLBACK_EXPIRED']);
            $processed++;
        }

        return $processed;
    }

    public function reviveLead(Lead $lead, User $supervisor): void
    {
        $this->poolService->markAsAvailable($lead);

        $this->auditService->log(
            lead: $lead,
            action: 'SUPERVISOR_OVERRIDE',
            user: $supervisor,
            metadata: ['action' => 'REVIVE_EXHAUSTED']
        );
    }
}
