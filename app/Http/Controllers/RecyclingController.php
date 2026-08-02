<?php

namespace App\Http\Controllers;

use App\Domain\Lead\Enums\LeadStatus;
use App\Domain\Lead\Enums\PoolStatus;
use App\Domain\Lead\Models\Lead;
use App\Models\LeadCycle;
use App\Models\RecyclingRule;
use App\Models\User;
use App\Services\LeadRecyclingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RecyclingController extends Controller
{
    public function __construct(
        private LeadRecyclingService $recyclingService,
    ) {}

    /**
     * Display the recycling dashboard with pool, rules, and stats.
     */
    public function index()
    {
        $leads = Lead::whereNotIn('pool_status', [PoolStatus::ASSIGNED, PoolStatus::EXHAUSTED])
            ->whereIn('status', [
                LeadStatus::NO_ANSWER,
                LeadStatus::CALLBACK,
            ])
            ->orderBy('updated_at', 'asc')
            ->get()
            ->map(function ($lead) {
                $lead->days_in_pool = now()->diffInDays($lead->updated_at);

                return $lead;
            });

        $agents = User::where('role', 'agent')
            ->where('is_active', true)
            ->get();

        $rules = RecyclingRule::orderBy('outcome')->get();

        $stats = $this->getStats();

        return Inertia::render('Recycling/Index', [
            'leads' => $leads,
            'agents' => $agents,
            'stats' => $stats,
            'rules' => $rules,
        ]);
    }

    /**
     * Get recycling stats as JSON (for API refresh).
     */
    public function stats(): JsonResponse
    {
        return response()->json($this->getStats());
    }

    /**
     * Get all recycling rules.
     */
    public function rules(): JsonResponse
    {
        $rules = RecyclingRule::orderBy('outcome')->get();

        return response()->json($rules);
    }

    /**
     * Create a new recycling rule.
     */
    public function storeRule(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'outcome' => ['required', 'string', 'in:NO_ANSWER,CALLBACK,INTERESTED,ORDERED,NOT_INTERESTED,WRONG_NUMBER'],
            'cooldown_hours' => ['required', 'integer', 'min:0', 'max:1440'],
            'max_cycles' => ['required', 'integer', 'min:1', 'max:999'],
            'next_action' => ['required', 'string', 'in:RECYCLE,EXHAUST'],
            'is_active' => ['boolean'],
        ]);

        $rule = RecyclingRule::create($validated);

        return response()->json($rule, 201);
    }

    /**
     * Update an existing recycling rule.
     */
    public function updateRule(Request $request, RecyclingRule $rule): JsonResponse
    {
        $validated = $request->validate([
            'cooldown_hours' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'max_cycles' => ['sometimes', 'integer', 'min:1', 'max:999'],
            'next_action' => ['sometimes', 'string', 'in:RECYCLE,EXHAUST'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $rule->update($validated);

        return response()->json($rule);
    }

    /**
     * Delete a recycling rule.
     */
    public function destroyRule(RecyclingRule $rule): JsonResponse
    {
        $rule->delete();

        return response()->json(null, 204);
    }

    /**
     * Manually trigger recycling processing.
     */
    public function trigger(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['sometimes', 'string', 'in:cooldown,callbacks,all'],
        ]);

        $type = $validated['type'] ?? 'all';

        $cooldownProcessed = 0;
        $callbackProcessed = 0;

        if ($type === 'cooldown' || $type === 'all') {
            $cooldownProcessed = $this->recyclingService->processExpiredCooldowns();
        }

        if ($type === 'callbacks' || $type === 'all') {
            $callbackProcessed = $this->recyclingService->processExpiredCallbacks();
        }

        $total = $cooldownProcessed + $callbackProcessed;

        return response()->json([
            'cooldown_processed' => $cooldownProcessed,
            'callbacks_processed' => $callbackProcessed,
            'total_processed' => $total,
        ]);
    }

    /**
     * Revive an exhausted lead back to available.
     */
    public function revive(Request $request, Lead $lead): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['sometimes', 'string', 'max:500'],
        ]);

        $supervisor = $request->user();
        $this->recyclingService->reviveLead($lead, $supervisor);

        return response()->json([
            'message' => 'Lead revived successfully',
            'lead_id' => $lead->id,
        ]);
    }

    /**
     * Compute recycling stats.
     */
    private function getStats(): array
    {
        $poolSize = Lead::whereNotIn('pool_status', [PoolStatus::ASSIGNED, PoolStatus::EXHAUSTED])
            ->whereIn('status', [
                LeadStatus::NO_ANSWER,
                LeadStatus::CALLBACK,
            ])
            ->count();

        $recycledToday = Lead::where('pool_status', PoolStatus::AVAILABLE)
            ->whereDate('updated_at', today())
            ->where('total_cycles', '>', 0)
            ->count();

        $driver = Lead::query()->getConnection()->getDriverName();
        $avgDaysExpr = $driver === 'sqlite'
            ? "AVG(JULIANDAY('now') - JULIANDAY(updated_at))"
            : 'AVG(EXTRACT(EPOCH FROM (NOW() - updated_at)) / 86400)';

        $avgDaysInPool = (float) Lead::whereNotIn('pool_status', [PoolStatus::ASSIGNED, PoolStatus::EXHAUSTED])
            ->whereIn('status', [
                LeadStatus::NO_ANSWER,
                LeadStatus::CALLBACK,
            ])
            ->selectRaw($avgDaysExpr.' as avg_days')
            ->value('avg_days');

        $reassignedToday = LeadCycle::whereDate('created_at', today())
            ->distinct('lead_id')
            ->count('lead_id');

        $cooldownCount = Lead::where('pool_status', PoolStatus::COOLDOWN)->count();
        $cooldownExpired = Lead::cooldownExpired()->count();
        $exhaustedCount = Lead::where('pool_status', PoolStatus::EXHAUSTED)->count();
        $availableCount = Lead::where('pool_status', PoolStatus::AVAILABLE)->count();

        $expiredCallbacks = LeadCycle::where('status', 'ACTIVE')
            ->whereNotNull('callback_at')
            ->where('callback_at', '<', now()->subHours(24))
            ->where('call_count', '>', 0)
            ->count();

        $rulesCount = RecyclingRule::where('is_active', true)->count();

        $outcomeBreakdown = LeadCycle::where('status', 'CLOSED')
            ->whereNotNull('outcome')
            ->selectRaw('outcome, COUNT(*) as count')
            ->groupBy('outcome')
            ->pluck('count', 'outcome')
            ->toArray();

        return [
            'pool_size' => $poolSize,
            'recycled_today' => $recycledToday,
            'avg_days_in_pool' => round($avgDaysInPool ?? 0, 1),
            'reassigned_today' => $reassignedToday,
            'cooldown_count' => $cooldownCount,
            'cooldown_expired' => $cooldownExpired,
            'exhausted_count' => $exhaustedCount,
            'available_count' => $availableCount,
            'expired_callbacks' => $expiredCallbacks,
            'rules_count' => $rulesCount,
            'outcome_breakdown' => $outcomeBreakdown,
        ];
    }
}
