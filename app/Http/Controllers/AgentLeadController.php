<?php

namespace App\Http\Controllers;

use App\Domain\Lead\Enums\LeadOutcome;
use App\Domain\Lead\Enums\PoolStatus;
use App\Domain\Lead\Models\Lead;
use App\Http\Resources\AgentLeadResource;
use App\Models\LeadCycle;
use App\Services\CallTrackingService;
use App\Services\LeadDistributionService;
use App\Services\LeadRecyclingService;
use App\Services\LeadPoolService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Inertia\Inertia;
use Inertia\Response;

class AgentLeadController extends Controller
{
    public function __construct(
        private CallTrackingService $callService,
        private LeadRecyclingService $recyclingService,
        private LeadDistributionService $distributionService,
        private LeadPoolService $poolService
    ) {}

    public function portal(Request $request): Response
    {
        $agent = auth()->user();
        $agent->load('agentProfile');
        $filters = $request->only(['status', 'search', 'product']);

        $productSkills = $agent->agentProfile?->product_skills ?? [];

        $query = Lead::where('assigned_to', $agent->id)
            ->whereIn('pool_status', [PoolStatus::ASSIGNED, PoolStatus::COOLDOWN])
            ->with(['customer', 'cycles' => fn ($q) => $q->where('assigned_agent_id', $agent->id)->orderBy('cycle_number', 'desc')]);

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('pool_status', $filters['status']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ILIKE', "%{$search}%")
                  ->orWhere('city', 'ILIKE', "%{$search}%")
                  ->orWhere('barangay', 'ILIKE', "%{$search}%");
            });
        }

        if (!empty($filters['product'])) {
            $query->where('product_name', 'ILIKE', "%{$filters['product']}%");
        }

        $leads = $query->orderBy('assigned_at', 'asc')->get();

        $todayCycles = LeadCycle::where('assigned_agent_id', $agent->id)
            ->whereDate('opened_at', today())
            ->get();

        $callbacksToday = Lead::where('assigned_to', $agent->id)
            ->whereHas('cycles', fn ($q) => $q
                ->where('assigned_agent_id', $agent->id)
                ->whereNotNull('callback_at')
                ->whereDate('callback_at', today())
                ->where('status', 'ACTIVE')
            )
            ->with(['customer', 'cycles'])
            ->get();

        // Count available matching leads in pool per product skill
        $matchingInPool = [];
        foreach ($productSkills as $skill) {
            $matchingInPool[$skill] = Lead::available()
                ->where('product_name', 'ILIKE', "%{$skill}%")
                ->count();
        }

        return Inertia::render('AgentLeads/Index', [
            'leads' => AgentLeadResource::collection($leads),
            'stats' => [
                'assigned' => Lead::where('assigned_to', $agent->id)
                    ->where('pool_status', PoolStatus::ASSIGNED)->count(),
                'called_today' => $todayCycles->where('call_count', '>', 0)->count(),
                'sold_today' => $todayCycles->where('outcome', 'ORDERED')->count(),
                'callbacks_due' => $callbacksToday->count(),
                'conversion_rate' => $todayCycles->count() > 0
                    ? round($todayCycles->where('outcome', 'ORDERED')->count() / $todayCycles->count() * 100, 1)
                    : 0,
            ],
            'poolStats' => $this->poolService->getPoolStats(),
            'filters' => $filters,
            'callbacksToday' => AgentLeadResource::collection($callbacksToday),
            'productSkills' => $productSkills,
            'matchingInPool' => $matchingInPool,
        ]);
    }

    public function requestLeads(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'count'   => ['sometimes', 'integer', 'min:1', 'max:10'],
            'product' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $agent = auth()->user();
        $agent->load('agentProfile');
        $count = $validated['count'] ?? 5;

        $activeLeads = Lead::where('assigned_to', $agent->id)
            ->where('pool_status', PoolStatus::ASSIGNED)
            ->count();

        $maxActive = $agent->agentProfile?->max_active_cycles ?? 10;
        $canRequest = max(0, $maxActive - $activeLeads);

        if ($canRequest === 0) {
            return response()->json([
                'message' => "You already have {$activeLeads} active leads. Finish some before requesting more.",
                'assigned' => 0,
            ], 422);
        }

        $toAssign = min($count, $canRequest);
        $productSkills = $agent->agentProfile?->product_skills ?? [];

        // Determine which product to filter by:
        // 1. Explicit product param from request
        // 2. Agent's registered product skills (match any)
        $requestedProduct = $validated['product'] ?? null;

        $query = Lead::available()->orderBy('created_at', 'asc');

        if ($requestedProduct) {
            // Explicit product filter requested
            $query->where('product_name', 'ILIKE', "%{$requestedProduct}%");
        } elseif (!empty($productSkills)) {
            // Filter by ANY of the agent's product skills
            $query->where(function ($q) use ($productSkills) {
                foreach ($productSkills as $skill) {
                    $q->orWhere('product_name', 'ILIKE', "%{$skill}%");
                }
            });
        }
        // If no skills set and no explicit filter → pull any available lead

        $availableLeads = $query->limit($toAssign)->pluck('id')->toArray();

        if (empty($availableLeads) && !empty($productSkills)) {
            // No matching product leads — try without product filter
            $availableLeads = Lead::available()
                ->orderBy('created_at', 'asc')
                ->limit($toAssign)
                ->pluck('id')
                ->toArray();

            if (empty($availableLeads)) {
                return response()->json([
                    'message' => 'No leads available in the pool right now. Please check back later.',
                    'assigned' => 0,
                ]);
            }

            $result = $this->distributionService->distributeCustom(
                $availableLeads,
                [$agent->id => count($availableLeads)],
                $agent->id
            );

            return response()->json([
                'message' => "No matching product leads available. Assigned {$result['total_distributed']} general lead(s) instead.",
                'assigned' => $result['total_distributed'],
            ]);
        }

        if (empty($availableLeads)) {
            return response()->json([
                'message' => 'No leads available in the pool right now. Please check back later.',
                'assigned' => 0,
            ]);
        }

        $result = $this->distributionService->distributeCustom(
            $availableLeads,
            [$agent->id => count($availableLeads)],
            $agent->id
        );

        $productLabel = $requestedProduct ?? (count($productSkills) === 1 ? $productSkills[0] : null);
        $message = $productLabel
            ? "Assigned {$result['total_distributed']} {$productLabel} lead(s) to you."
            : "Successfully assigned {$result['total_distributed']} lead(s) to you.";

        return response()->json([
            'message' => $message,
            'assigned' => $result['total_distributed'],
        ]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $leads = Lead::where('assigned_to', auth()->id())
            ->where('pool_status', PoolStatus::ASSIGNED)
            ->with(['customer', 'cycles' => fn ($q) => $q->where('assigned_agent_id', auth()->id())])
            ->orderBy('assigned_at', 'asc')
            ->get();

        return AgentLeadResource::collection($leads);
    }

    public function show(Lead $lead): AgentLeadResource
    {
        if ($lead->assigned_to !== auth()->id()) {
            abort(403, 'You are not assigned to this lead');
        }

        $lead->load(['customer', 'cycles' => fn ($q) => $q->where('assigned_agent_id', auth()->id())]);

        return new AgentLeadResource($lead);
    }

    public function call(Lead $lead): JsonResponse
    {
        if ($lead->assigned_to !== auth()->id()) {
            abort(403, 'You are not assigned to this lead');
        }

        $cycle = $lead->cycles()
            ->where('assigned_agent_id', auth()->id())
            ->where('status', 'ACTIVE')
            ->firstOrFail();

        $sipLink = $this->callService->initiateCall($lead, $cycle, auth()->user());

        return response()->json([
            'sip_link' => $sipLink,
            'call_count' => $cycle->fresh()->call_count,
        ]);
    }

    public function outcome(Request $request, Lead $lead): JsonResponse
    {
        if ($lead->assigned_to !== auth()->id()) {
            abort(403, 'You are not assigned to this lead');
        }

        $validated = $request->validate([
            'outcome' => ['required', 'string', 'in:NO_ANSWER,CALLBACK,INTERESTED,ORDERED,NOT_INTERESTED,WRONG_NUMBER'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'callback_at' => ['nullable', 'required_if:outcome,CALLBACK', 'date', 'after:now'],
        ]);

        $cycle = $lead->cycles()
            ->where('assigned_agent_id', auth()->id())
            ->where('status', 'ACTIVE')
            ->firstOrFail();

        $outcome = LeadOutcome::from($validated['outcome']);
        $callbackAt = isset($validated['callback_at']) ? new \DateTime($validated['callback_at']) : null;

        $this->recyclingService->processOutcome(
            $lead,
            $cycle,
            $outcome,
            auth()->user(),
            $validated['remarks'] ?? null,
            $callbackAt
        );

        return response()->json([
            'message' => 'Outcome recorded',
            'lead' => new AgentLeadResource($lead->fresh(['customer', 'cycles'])),
        ]);
    }

    public function callbacks(): AnonymousResourceCollection
    {
        $leads = Lead::where('assigned_to', auth()->id())
            ->whereHas('cycles', fn ($q) => $q->where('assigned_agent_id', auth()->id())
                  ->whereNotNull('callback_at')
                  ->where('status', 'ACTIVE')
            )
            ->with(['customer', 'cycles'])
            ->get();

        return AgentLeadResource::collection($leads);
    }
}
