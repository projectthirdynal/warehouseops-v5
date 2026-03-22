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
        // Close the cycle
        $cycle->update([
            'status' => 'CLOSED',
            'outcome' => $outcome->value,
            'closed_at' => now(),
            'callback_at' => $callbackAt,
            'callback_notes' => $callbackAt ? $remarks : null,
        ]);

        // Log outcome
        $this->auditService->log(
            lead: $lead,
            action: 'OUTCOME_SET',
            user: $agent,
            cycle: $cycle,
            newValue: $outcome->value,
            metadata: ['remarks' => $remarks, 'callback_at' => $callbackAt?->format('c')]
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

        // Handle CALLBACK - keep with agent
        if ($outcome === LeadOutcome::CALLBACK && $callbackAt) {
            // Keep assigned, don't change status
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
