<?php

namespace App\Jobs;

use Modules\Leads\Enums\PoolStatus;
use Modules\Leads\Models\Lead;
use App\Events\LeadAssigned;
use App\Models\DistributionQueue;
use App\Models\LeadCycle;
use App\Models\User;
use App\Services\CapacityManager;
use App\Services\DistributionEngine;
use App\Services\LeadAuditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AutoDistributeLeads implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var int[] */
    public array $backoff = [30, 120, 300];

    public function __construct(
        private int $batchSize = 20,
    ) {}

    public function handle(
        DistributionEngine $engine,
        LeadAuditService $auditService,
    ): void {
        $distributed = 0;
        $queued = 0;

        // 1. Process pending distribution queue items first
        $pendingQueue = DistributionQueue::pending()
            ->where('attempt_count', '<', 4)
            ->limit($this->batchSize)
            ->get();

        foreach ($pendingQueue as $queueItem) {
            $lead = Lead::find($queueItem->lead_id);
            if (! $lead || $lead->pool_status !== PoolStatus::AVAILABLE) {
                $queueItem->update(['status' => 'cancelled']);

                continue;
            }

            $result = $engine->findBestAgent($lead);

            if (! $result['agent_id']) {
                $queueItem->incrementAttempt();
                if ($queueItem->attempt_count >= 3) {
                    $queueItem->update(['status' => 'failed']);
                }

                continue;
            }

            $agent = User::find($result['agent_id']);
            if (! $agent) {
                $queueItem->incrementAttempt();

                continue;
            }

            $this->assignLead($lead, $agent, $result, $auditService);
            $queueItem->markAsAssigned($agent->id, ['score' => $result['score'], 'reason' => $result['reason']]);
            $distributed++;
        }

        // 2. Then process fresh available leads
        // Prioritize distribution: higher quality_score leads get processed first (C1: Lead Scoring)
        $remaining = $this->batchSize - $distributed;
        if ($remaining > 0) {
            $leads = Lead::where('pool_status', PoolStatus::AVAILABLE)
                ->whereDoesntHave('distributionQueues', fn ($q) => $q->whereIn('status', ['pending', 'assigned']))
                ->orderByDesc('quality_score')
                ->orderBy('created_at')
                ->limit($remaining)
                ->get();

            foreach ($leads as $lead) {
                $result = $engine->findBestAgent($lead);

                if (! $result['agent_id']) {
                    DistributionQueue::create([
                        'lead_id' => $lead->id,
                        'rule_id' => $result['rule_id'],
                        'status' => 'pending',
                    ]);
                    $queued++;

                    continue;
                }

                $agent = User::find($result['agent_id']);
                if (! $agent) {
                    DistributionQueue::create([
                        'lead_id' => $lead->id,
                        'rule_id' => $result['rule_id'],
                        'status' => 'pending',
                    ]);
                    $queued++;

                    continue;
                }

                $this->assignLead($lead, $agent, $result, $auditService);
                $distributed++;
            }
        }

        Log::info("AutoDistributeLeads: {$distributed} distributed, {$queued} queued");
    }

    private function assignLead(Lead $lead, User $agent, array $result, LeadAuditService $auditService): void
    {
        DB::transaction(function () use ($lead, $agent, $result, $auditService) {
            // Race-condition guard: re-check lead is still available
            $lead->refresh();
            if ($lead->pool_status !== PoolStatus::AVAILABLE) {
                return;
            }

            $cycleNumber = $lead->total_cycles + 1;
            $cycle = LeadCycle::create([
                'lead_id' => $lead->id,
                'cycle_number' => $cycleNumber,
                'assigned_agent_id' => $agent->id,
                'status' => 'ACTIVE',
                'opened_at' => now(),
            ]);

            $lead->update([
                'pool_status' => PoolStatus::ASSIGNED,
                'assigned_to' => $agent->id,
                'assigned_at' => now(),
                'total_cycles' => $cycleNumber,
            ]);

            // Update agent workload counters
            app(CapacityManager::class)->recordAssignment($agent->id);

            $auditService->log(
                lead: $lead,
                action: 'AUTO_DISTRIBUTED',
                user: $agent,
                cycle: $cycle,
                metadata: [
                    'agent_id' => $agent->id,
                    'agent_name' => $agent->name,
                    'score' => $result['score'],
                    'reason' => $result['reason'],
                ]
            );

            LeadAssigned::dispatch($lead, $agent, $cycle, $result['reason']);
        });
    }
}
