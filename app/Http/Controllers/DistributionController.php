<?php

namespace App\Http\Controllers;

use App\Domain\Lead\Enums\PoolStatus;
use App\Domain\Lead\Models\Lead;
use App\Events\LeadAssigned;
use App\Models\AgentWorkload;
use App\Models\DistributionQueue;
use App\Models\DistributionRule;
use App\Models\LeadCycle;
use App\Models\PredictiveModelData;
use App\Models\User;
use App\Services\CapacityManager;
use App\Services\DistributionEngine;
use App\Services\LeadAuditService;
use App\Services\PredictiveAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DistributionController extends Controller
{
    public function __construct(
        private DistributionEngine $engine,
        private LeadAuditService $auditService,
    ) {
        $this->middleware(function ($request, $next) {
            if (! in_array(auth()->user()->role, ['superadmin', 'admin', 'supervisor'])) {
                abort(403, 'Unauthorized');
            }

            return $next($request);
        });
    }

    public function index()
    {
        return Inertia::render('Distribution/Index', [
            'rules' => DistributionRule::active()->get(),
            'queue' => DistributionQueue::with(['lead', 'assignedAgent'])
                ->orderByDesc('created_at')
                ->limit(50)
                ->get(),
            'agents' => User::where('role', 'agent')
                ->where('is_active', true)
                ->with('agentProfile')
                ->get(),
            'workloads' => AgentWorkload::with('agent')
                ->get()
                ->keyBy('agent_id'),
        ]);
    }

    public function storeRule(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'strategy' => 'required|string|in:round_robin,weighted,skill_match,territory,hybrid,predictive',
            'priority' => 'required|integer|min:0',
            'filters' => 'nullable|array',
            'filters.conditions' => 'nullable|array',
            'filters.conditions.min_quality_score' => 'nullable|integer|min:0|max:100',
            'filters.conditions.max_quality_score' => 'nullable|integer|min:0|max:100',
            'filters.conditions.lead_regions' => 'nullable|array',
            'filters.conditions.lead_products' => 'nullable|array',
            'filters.conditions.lead_sources' => 'nullable|array',
            'filters.conditions.min_amount' => 'nullable|numeric|min:0',
            'filters.conditions.max_amount' => 'nullable|numeric|min:0',
            'weight_formula' => 'nullable|array',
            'is_active' => 'boolean',
        ]);

        $rule = DistributionRule::create([
            ...$validated,
            'supervisor_id' => $request->user()->id,
        ]);

        return redirect()->back()->with('success', "Rule '{$rule->name}' created.");
    }

    public function updateRule(Request $request, DistributionRule $rule)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'strategy' => 'sometimes|string|in:round_robin,weighted,skill_match,territory,hybrid,predictive',
            'priority' => 'sometimes|integer|min:0',
            'filters' => 'nullable|array',
            'filters.conditions' => 'nullable|array',
            'filters.conditions.min_quality_score' => 'nullable|integer|min:0|max:100',
            'filters.conditions.max_quality_score' => 'nullable|integer|min:0|max:100',
            'filters.conditions.lead_regions' => 'nullable|array',
            'filters.conditions.lead_products' => 'nullable|array',
            'filters.conditions.lead_sources' => 'nullable|array',
            'filters.conditions.min_amount' => 'nullable|numeric|min:0',
            'filters.conditions.max_amount' => 'nullable|numeric|min:0',
            'weight_formula' => 'nullable|array',
            'is_active' => 'boolean',
        ]);

        $rule->update($validated);

        return redirect()->back()->with('success', "Rule '{$rule->name}' updated.");
    }

    public function destroyRule(DistributionRule $rule)
    {
        $rule->delete();

        return redirect()->back()->with('success', 'Rule deleted.');
    }

    public function assign(Request $request)
    {
        $validated = $request->validate([
            'lead_id' => 'required|exists:leads,id',
            'agent_id' => 'required|exists:users,id',
            'reason' => 'required|string|max:500',
        ]);

        $lead = Lead::findOrFail($validated['lead_id']);
        $agent = User::findOrFail($validated['agent_id']);

        if ($lead->pool_status !== PoolStatus::AVAILABLE) {
            return redirect()->back()->with('error', 'Lead is not available for assignment.');
        }

        $result = DB::transaction(function () use ($lead, $agent, $validated) {
            // Race-condition guard
            $lead->refresh();
            if ($lead->pool_status !== PoolStatus::AVAILABLE) {
                throw new \RuntimeException('Lead is no longer available');
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

            // Update agent workload
            app(CapacityManager::class)->recordAssignment($agent->id);

            $this->auditService->log(
                lead: $lead,
                action: 'MANUALLY_ASSIGNED',
                user: auth()->user(),
                cycle: $cycle,
                metadata: [
                    'agent_id' => $agent->id,
                    'agent_name' => $agent->name,
                    'reason' => $validated['reason'],
                ]
            );

            LeadAssigned::dispatch($lead, $agent, $cycle, $validated['reason']);

            return ['lead' => $lead, 'cycle' => $cycle];
        });

        return redirect()->back()->with('success', "Lead assigned to {$agent->name}.");
    }

    public function reassign(Request $request)
    {
        $validated = $request->validate([
            'lead_id' => 'required|exists:leads,id',
            'agent_id' => 'required|exists:users,id',
            'reason' => 'required|string|max:500',
        ]);

        $lead = Lead::findOrFail($validated['lead_id']);
        $agent = User::findOrFail($validated['agent_id']);

        try {
            $this->distributionService->reassign($lead, $agent, $validated['reason'], auth()->user());
        } catch (\RuntimeException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', "Lead reassigned to {$agent->name}.");
    }

    /**
     * Bulk reassign — Phase 4 L1: Batch Operations.
     * Reassigns multiple ASSIGNED leads to a single agent in one action.
     */
    public function bulkReassign(Request $request)
    {
        $validated = $request->validate([
            'lead_ids' => ['required', 'array', 'min:1'],
            'lead_ids.*' => ['integer', 'exists:leads,id'],
            'agent_id' => ['required', 'exists:users,id'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $agent = User::findOrFail($validated['agent_id']);

        $result = $this->distributionService->bulkReassign(
            $validated['lead_ids'],
            $agent,
            $validated['reason'],
            auth()->user()
        );

        if ($result['reassigned'] === 0) {
            return redirect()->back()->with('error', 'No leads could be reassigned. '.implode(' ', $result['errors']));
        }

        return redirect()->back()
            ->with('success', "Reassigned {$result['reassigned']} lead(s) to {$agent->name}.".($result['failed'] > 0 ? " {$result['failed']} failed." : ''))
            ->with('bulkActionErrors', $result['errors']);
    }

    public function autoDistribute(Request $request)
    {
        $validated = $request->validate([
            'limit' => 'integer|min:1|max:100',
        ]);
        $limit = $validated['limit'] ?? 10;
        $distributed = 0;

        // Prioritize distribution: higher quality_score leads get processed first (C1: Lead Scoring)
        $leads = Lead::where('pool_status', PoolStatus::AVAILABLE)
            ->orderByDesc('quality_score')
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        foreach ($leads as $lead) {
            $result = $this->engine->findBestAgent($lead);

            if (! $result['agent_id']) {
                DistributionQueue::create([
                    'lead_id' => $lead->id,
                    'rule_id' => $result['rule_id'],
                    'status' => 'pending',
                ]);

                continue;
            }

            $agent = User::find($result['agent_id']);
            if (! $agent) {
                continue;
            }

            $assigned = DB::transaction(function () use ($lead, $agent, $result): bool {
                // Race-condition guard (ISS-002)
                $lead->refresh();
                if ($lead->pool_status !== PoolStatus::AVAILABLE) {
                    return false;
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

                app(CapacityManager::class)->recordAssignment($agent->id);

                $this->auditService->log(
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

                return true;
            });

            if ($assigned) {
                $distributed++;
            }
        }

        return redirect()->back()->with('success', "{$distributed} leads distributed.");
    }

    public function queue()
    {
        return response()->json([
            'pending' => DistributionQueue::pending()->count(),
            'assigned' => DistributionQueue::where('status', 'assigned')->count(),
            'failed' => DistributionQueue::where('status', 'failed')->count(),
            'items' => DistributionQueue::with(['lead', 'assignedAgent'])
                ->orderByDesc('created_at')
                ->limit(20)
                ->get(),
        ]);
    }

    public function agentWorkload(User $agent)
    {
        $workload = AgentWorkload::firstOrCreate(
            ['agent_id' => $agent->id],
            ['active_leads_count' => 0, 'today_assigned_count' => 0, 'today_converted_count' => 0]
        );

        $profile = $agent->agentProfile;
        $maxCycles = $profile?->concurrent_lead_cap ?? $profile?->max_active_cycles ?? 10;

        return response()->json([
            'agent_id' => $agent->id,
            'name' => $agent->name,
            'active_leads' => $workload->active_leads_count,
            'max_cycles' => $maxCycles,
            'utilization' => $maxCycles > 0 ? round($workload->active_leads_count / $maxCycles, 2) : 0,
            'today_assigned' => $workload->today_assigned_count,
            'today_converted' => $workload->today_converted_count,
            'last_assigned_at' => $workload->last_assigned_at,
        ]);
    }

    /**
     * Preview predictive assignment for a specific lead.
     * Returns ranked agent predictions without actually assigning.
     */
    public function predict(Request $request)
    {
        $validated = $request->validate([
            'lead_id' => 'required|exists:leads,id',
        ]);

        $lead = Lead::findOrFail($validated['lead_id']);
        $service = app(PredictiveAssignmentService::class);

        // Get all eligible agents (same filtering as engine, but without rule-specific filters)
        $eligible = $this->engine->filterEligibleAgents($lead, null);

        if ($eligible->isEmpty()) {
            return response()->json([
                'lead_id' => $lead->id,
                'predictions' => [],
                'reason' => 'No eligible agents available',
            ]);
        }

        $result = $service->predict($lead, $eligible);

        // Build ranked list with factors for all eligible agents
        $predictions = $eligible->map(function ($agent) use ($service, $lead) {
            $single = $service->predict($lead, collect([$agent]));

            return [
                'agent_id' => $agent->user_id,
                'agent_name' => $agent->user?->name ?? 'Unknown',
                'score' => $single['score'],
                'factors' => $single['factors'],
            ];
        })->sortByDesc('score')->values()->all();

        return response()->json([
            'lead_id' => $lead->id,
            'lead_name' => $lead->name,
            'best_agent_id' => $result['agent_id'],
            'best_score' => $result['score'],
            'reason' => $result['reason'],
            'predictions' => $predictions,
        ]);
    }

    /**
     * Get the current predictive model status (trained data for all agents).
     */
    public function modelStatus()
    {
        $modelData = PredictiveModelData::with('agent')
            ->where('model_version', 'v1')
            ->orderByDesc('overall_score')
            ->get();

        return response()->json([
            'model_version' => 'v1',
            'agents_trained' => $modelData->count(),
            'last_trained_at' => $modelData->max('trained_at'),
            'agents' => $modelData->map(fn ($d) => [
                'agent_id' => $d->agent_id,
                'agent_name' => $d->agent?->name ?? 'Unknown',
                'conversion_rate' => round($d->conversion_rate * 100, 1),
                'avg_handle_time_hrs' => $d->avg_handle_time_hrs,
                'overall_score' => round($d->overall_score * 100, 1),
                'total_cycles' => $d->total_cycles,
                'total_sales' => $d->total_sales,
                'trained_at' => $d->trained_at,
            ]),
        ]);
    }

    /**
     * Trigger predictive model retraining.
     */
    public function retrain()
    {
        $service = app(PredictiveAssignmentService::class);
        $result = $service->retrain();

        return redirect()->back()->with(
            'success',
            "Predictive model retrained: {$result['agents_trained']} agents, {$result['total_cycles']} cycles, {$result['total_sales']} sales."
        );
    }
}
