<?php

namespace App\Services;

use Modules\Leads\Models\Lead;
use Modules\Orders\Models\Order;
use App\Models\LeadCycle;
use App\Models\LeadLog;
use App\Models\LeadPoolAudit;
use Illuminate\Support\Collection;

class LeadLifecycleService
{
    public function __construct(
        private LeadAuditService $auditService,
    ) {}

    /**
     * Build a unified, chronologically-sorted lifecycle timeline for a lead.
     *
     * Aggregates events from:
     * - Lead creation (import)
     * - LeadPoolAudit (distribution, assignment, pool status changes, outcomes)
     * - LeadCycle (cycle open/close, calls, callbacks)
     * - LeadLog (general lead logs)
     * - Orders (created from lead)
     * - Waybills (dispatched, delivered, returned)
     * - QA Reviews
     *
     * @return array{events: Collection<int, array>, summary: array}
     */
    public function getLifecycle(Lead $lead): array
    {
        $events = collect();

        // 1. Lead creation / import
        $events->push([
            'type' => 'import',
            'action' => 'LEAD_IMPORTED',
            'label' => 'Lead Imported',
            'description' => "Lead imported from source: {$lead->source?->label()}",
            'timestamp' => $lead->created_at,
            'user' => $lead->uploader?->name,
            'metadata' => [
                'source' => $lead->source?->value,
                'quality_score' => $lead->quality_score,
                'product_name' => $lead->product_name,
                'amount' => $lead->amount,
            ],
        ]);

        // 2. Pool audit logs (distribution, assignment, status changes, outcomes)
        $audits = LeadPoolAudit::where('lead_id', $lead->id)
            ->with(['user', 'leadCycle'])
            ->orderBy('created_at')
            ->get();

        foreach ($audits as $audit) {
            $events->push([
                'type' => 'audit',
                'action' => $audit->action,
                'label' => $this->getAuditLabel($audit->action),
                'description' => $this->getAuditDescription($audit),
                'timestamp' => $audit->created_at,
                'user' => $audit->user?->name,
                'cycle_id' => $audit->lead_cycle_id,
                'metadata' => $audit->metadata,
                'old_value' => $audit->old_value,
                'new_value' => $audit->new_value,
            ]);
        }

        // 3. Lead cycles (open, calls, callbacks, close)
        $cycles = LeadCycle::where('lead_id', $lead->id)
            ->with('assignedAgent')
            ->orderBy('opened_at')
            ->get();

        foreach ($cycles as $cycle) {
            // Cycle opened
            $events->push([
                'type' => 'cycle',
                'action' => 'CYCLE_OPENED',
                'label' => "Cycle {$cycle->cycle_number} Opened",
                'description' => "Assigned to {$cycle->assignedAgent?->name}",
                'timestamp' => $cycle->opened_at,
                'user' => $cycle->assignedAgent?->name,
                'cycle_id' => $cycle->id,
                'metadata' => [
                    'cycle_number' => $cycle->cycle_number,
                    'agent_id' => $cycle->assigned_agent_id,
                ],
            ]);

            // Calls made during cycle
            if ($cycle->call_count > 0 && $cycle->last_call_at) {
                $events->push([
                    'type' => 'call',
                    'action' => 'CALL_MADE',
                    'label' => "Call Made (Cycle {$cycle->cycle_number})",
                    'description' => "{$cycle->call_count} call(s) made, last at {$cycle->last_call_at->format('M j, Y g:i A')}",
                    'timestamp' => $cycle->last_call_at,
                    'user' => $cycle->assignedAgent?->name,
                    'cycle_id' => $cycle->id,
                    'metadata' => [
                        'call_count' => $cycle->call_count,
                        'cycle_number' => $cycle->cycle_number,
                    ],
                ]);
            }

            // Callback scheduled
            if ($cycle->callback_at) {
                $events->push([
                    'type' => 'callback',
                    'action' => 'CALLBACK_SCHEDULED',
                    'label' => "Callback Scheduled (Cycle {$cycle->cycle_number})",
                    'description' => "Callback for {$cycle->callback_at->format('M j, Y g:i A')}",
                    'timestamp' => $cycle->callback_at,
                    'user' => $cycle->assignedAgent?->name,
                    'cycle_id' => $cycle->id,
                    'metadata' => [
                        'callback_notes' => $cycle->callback_notes,
                        'cycle_number' => $cycle->cycle_number,
                    ],
                ]);
            }

            // Cycle closed
            if ($cycle->closed_at) {
                $events->push([
                    'type' => 'cycle',
                    'action' => 'CYCLE_CLOSED',
                    'label' => "Cycle {$cycle->cycle_number} Closed",
                    'description' => "Outcome: {$cycle->outcome}",
                    'timestamp' => $cycle->closed_at,
                    'user' => $cycle->assignedAgent?->name,
                    'cycle_id' => $cycle->id,
                    'metadata' => [
                        'cycle_number' => $cycle->cycle_number,
                        'outcome' => $cycle->outcome,
                    ],
                ]);
            }
        }

        // 4. Lead logs (general)
        $logs = LeadLog::where('lead_id', $lead->id)
            ->with('user')
            ->orderBy('created_at')
            ->get();

        foreach ($logs as $log) {
            $events->push([
                'type' => 'log',
                'action' => $log->action,
                'label' => ucwords(strtolower(str_replace('_', ' ', $log->action))),
                'description' => $log->notes ?? $log->action,
                'timestamp' => $log->created_at,
                'user' => $log->user?->name,
                'metadata' => $log->metadata,
                'old_value' => $log->old_value,
                'new_value' => $log->new_value,
            ]);
        }

        // 5. Orders created from lead
        $orders = Order::where('lead_id', $lead->id)
            ->with('agent')
            ->orderBy('created_at')
            ->get();

        foreach ($orders as $order) {
            $events->push([
                'type' => 'order',
                'action' => 'ORDER_CREATED',
                'label' => "Order {$order->order_number} Created",
                'description' => "Status: {$order->status->value}, Total: ₱{$order->total_amount}",
                'timestamp' => $order->created_at,
                'user' => $order->agent?->name,
                'metadata' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'status' => $order->status->value,
                    'total_amount' => $order->total_amount,
                ],
            ]);
        }

        // 6. Waybills
        $waybills = $lead->waybills()
            ->orderBy('created_at')
            ->get();

        foreach ($waybills as $waybill) {
            $events->push([
                'type' => 'waybill',
                'action' => 'WAYBILL_CREATED',
                'label' => "Waybill {$waybill->waybill_number} Created",
                'description' => "Courier: {$waybill->courier_provider}, COD: ₱{$waybill->cod_amount}",
                'timestamp' => $waybill->created_at,
                'metadata' => [
                    'waybill_id' => $waybill->id,
                    'waybill_number' => $waybill->waybill_number,
                    'courier' => $waybill->courier_provider,
                    'status' => $waybill->status?->value,
                ],
            ]);

            if ($waybill->dispatched_at) {
                $events->push([
                    'type' => 'waybill',
                    'action' => 'WAYBILL_DISPATCHED',
                    'label' => "Waybill {$waybill->waybill_number} Dispatched",
                    'description' => "Dispatched via {$waybill->courier_provider}",
                    'timestamp' => $waybill->dispatched_at,
                    'metadata' => [
                        'waybill_number' => $waybill->waybill_number,
                        'courier' => $waybill->courier_provider,
                    ],
                ]);
            }

            if ($waybill->delivered_at) {
                $events->push([
                    'type' => 'waybill',
                    'action' => 'WAYBILL_DELIVERED',
                    'label' => "Waybill {$waybill->waybill_number} Delivered",
                    'description' => 'Delivered successfully',
                    'timestamp' => $waybill->delivered_at,
                    'metadata' => [
                        'waybill_number' => $waybill->waybill_number,
                    ],
                ]);
            }

            if ($waybill->returned_at) {
                $events->push([
                    'type' => 'waybill',
                    'action' => 'WAYBILL_RETURNED',
                    'label' => "Waybill {$waybill->waybill_number} Returned",
                    'description' => $waybill->rts_reason ?? 'Returned to sender',
                    'timestamp' => $waybill->returned_at,
                    'metadata' => [
                        'waybill_number' => $waybill->waybill_number,
                        'rts_reason' => $waybill->rts_reason,
                    ],
                ]);
            }
        }

        // 7. QA Reviews (defensive — QaReview model may not exist yet)
        try {
            $qaReviews = $lead->qaReviews()
                ->with('reviewer')
                ->orderBy('created_at')
                ->get();

            foreach ($qaReviews as $qa) {
                $events->push([
                    'type' => 'qa',
                    'action' => 'QA_REVIEW',
                    'label' => "QA Review (Level {$qa->qa_level})",
                    'description' => "Decision: {$qa->decision}, Status: {$qa->qa_status}",
                    'timestamp' => $qa->reviewed_at ?? $qa->created_at,
                    'user' => $qa->reviewer?->name,
                    'metadata' => [
                        'qa_level' => $qa->qa_level,
                        'decision' => $qa->decision,
                        'qa_status' => $qa->qa_status,
                        'notes' => $qa->notes,
                    ],
                ]);
            }
        } catch (\Throwable $e) {
            // QaReview model not yet implemented — skip QA events
        }

        // Sort all events by timestamp ascending
        $sorted = $events->sortBy('timestamp')->values();

        // Build summary
        $summary = $this->buildSummary($lead, $cycles, $sorted);

        return [
            'events' => $sorted,
            'summary' => $summary,
        ];
    }

    /**
     * Build a summary of the lead's lifecycle.
     */
    private function buildSummary(Lead $lead, Collection $cycles, Collection $events): array
    {
        $totalCalls = $cycles->sum('call_count');
        $totalCallbacks = $cycles->whereNotNull('callback_at')->count();
        $completedCycles = $cycles->where('status', 'CLOSED')->count();
        $activeCycles = $cycles->where('status', 'ACTIVE')->count();

        // Find first and last event timestamps
        $firstEvent = $events->first();
        $lastEvent = $events->last();

        // Count distinct agents
        $distinctAgents = $cycles->pluck('assigned_agent_id')->unique()->count();

        // Outcome distribution
        $outcomes = $cycles->whereNotNull('outcome')
            ->pluck('outcome')
            ->countBy()
            ->toArray();

        return [
            'total_cycles' => $cycles->count(),
            'completed_cycles' => $completedCycles,
            'active_cycles' => $activeCycles,
            'total_calls' => $totalCalls,
            'total_callbacks' => $totalCallbacks,
            'distinct_agents' => $distinctAgents,
            'outcomes' => $outcomes,
            'first_event_at' => $firstEvent['timestamp'] ?? null,
            'last_event_at' => $lastEvent['timestamp'] ?? null,
            'current_status' => $lead->status?->value,
            'current_pool_status' => $lead->pool_status?->value,
            'current_sales_status' => $lead->sales_status?->value,
            'is_exhausted' => $lead->is_exhausted,
            'quality_score' => $lead->quality_score,
        ];
    }

    private function getAuditLabel(string $action): string
    {
        return match ($action) {
            'DISTRIBUTED' => 'Lead Distributed',
            'AUTO_DISTRIBUTED' => 'Auto-Distributed',
            'MANUALLY_ASSIGNED' => 'Manually Assigned',
            'REASSIGNED' => 'Reassigned',
            'POOL_STATUS_CHANGED' => 'Pool Status Changed',
            'OUTCOME_SET' => 'Outcome Set',
            'ORDER_CREATED' => 'Order Created',
            'CALLBACK_SCHEDULED' => 'Callback Scheduled',
            'SUPERVISOR_OVERRIDE' => 'Supervisor Override',
            'QUALITY_SCORED' => 'Quality Scored',
            default => ucwords(strtolower(str_replace('_', ' ', $action))),
        };
    }

    private function getAuditDescription(LeadPoolAudit $audit): string
    {
        $parts = [];

        if ($audit->old_value && $audit->new_value) {
            $parts[] = "{$audit->old_value} → {$audit->new_value}";
        } elseif ($audit->new_value) {
            $parts[] = $audit->new_value;
        }

        if ($audit->metadata && isset($audit->metadata['reason'])) {
            $parts[] = $audit->metadata['reason'];
        }

        if ($audit->metadata && isset($audit->metadata['agent_name'])) {
            $parts[] = "Agent: {$audit->metadata['agent_name']}";
        }

        return implode(' · ', $parts) ?: $audit->action;
    }
}
