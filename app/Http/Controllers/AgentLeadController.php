<?php

namespace App\Http\Controllers;

use App\Domain\Lead\Enums\LeadOutcome;
use App\Domain\Lead\Enums\PoolStatus;
use App\Domain\Lead\Models\Lead;
use App\Http\Resources\AgentLeadResource;
use App\Services\CallTrackingService;
use App\Services\LeadRecyclingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AgentLeadController extends Controller
{
    public function __construct(
        private CallTrackingService $callService,
        private LeadRecyclingService $recyclingService
    ) {}

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
