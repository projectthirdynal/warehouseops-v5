<?php

namespace App\Http\Controllers;

use App\Domain\Courier\Models\ShippingDay;
use App\Domain\Courier\Services\DeliveryEtaService;
use App\Domain\Lead\Enums\LeadOutcome;
use App\Domain\Lead\Enums\PoolStatus;
use App\Domain\Lead\Models\Lead;
use App\Domain\Shop\Services\GamificationService;
use App\Http\Resources\AgentLeadResource;
use App\Models\CoachingNote;
use App\Models\LeadCycle;
use App\Models\Waybill;
use App\Services\AgentPortalService;
use App\Services\CallTrackingService;
use App\Services\LeadDistributionService;
use App\Services\LeadPoolService;
use App\Services\LeadRecyclingService;
use App\Services\PoolReservationService;
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
        private LeadPoolService $poolService,
        private AgentPortalService $portalService,
        private GamificationService $gamificationService,
        private PoolReservationService $poolReservationService,
        private DeliveryEtaService $etaService,
    ) {}

    public function dashboard(): Response
    {
        $agent = auth()->user();
        $agent->load('agentProfile');

        $data = $this->portalService->getDashboardData($agent);

        $gamificationProfile = $this->gamificationService->getAgentProfile($agent->id);

        $coachingNotes = CoachingNote::where('agent_id', $agent->id)
            ->unresolved()
            ->with('author:id,name')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return Inertia::render('AgentLeads/Dashboard', [
            'earnings' => $data['earnings'],
            'recent_commissions' => $data['recent_commissions'],
            'lead_history' => $data['lead_history'],
            'leaderboard' => $data['leaderboard'],
            'workload' => $data['workload'],
            'agent' => [
                'id' => $agent->id,
                'name' => $agent->name,
                'performance_score' => (float) ($agent->agentProfile?->performance_score ?? 0),
                'is_available' => (bool) ($agent->agentProfile?->is_available ?? false),
            ],
            'gamification' => [
                'current_streak' => $gamificationProfile['streak']['current'] ?? 0,
                'longest_streak' => $gamificationProfile['streak']['longest'] ?? 0,
                'total_badges' => $gamificationProfile['total_badges'] ?? 0,
                'total_badges_available' => count($gamificationProfile['available_badges'] ?? []),
                'total_milestones_completed' => $gamificationProfile['total_milestones_completed'] ?? 0,
                'total_milestones' => count($gamificationProfile['milestones'] ?? []),
            ],
            'coachingNotes' => $coachingNotes,
        ]);
    }

    public function portal(Request $request): Response
    {
        $agent = auth()->user();
        $agent->load('agentProfile');
        $filters = $request->only(['status', 'search', 'product']);

        $productSkills = $agent->agentProfile?->product_skills ?? [];

        $query = Lead::where('assigned_to', $agent->id)
            ->whereIn('pool_status', [PoolStatus::ASSIGNED, PoolStatus::COOLDOWN])
            ->with(['customer', 'cycles' => fn ($q) => $q->where('assigned_agent_id', $agent->id)->orderBy('cycle_number', 'desc')]);

        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('pool_status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ILIKE', "%{$search}%")
                    ->orWhere('city', 'ILIKE', "%{$search}%")
                    ->orWhere('barangay', 'ILIKE', "%{$search}%");
            });
        }

        if (! empty($filters['product'])) {
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

        // Count available matching leads in pool per product skill (single query)
        $matchingInPool = [];
        if (! empty($productSkills)) {
            $poolCounts = Lead::available()
                ->where(function ($q) use ($productSkills) {
                    foreach ($productSkills as $skill) {
                        $q->orWhere('product_name', 'ILIKE', "%{$skill}%");
                    }
                })
                ->selectRaw('product_name, count(*) as cnt')
                ->groupBy('product_name')
                ->pluck('cnt', 'product_name');

            foreach ($productSkills as $skill) {
                $matchingInPool[$skill] = $poolCounts->filter(
                    fn ($cnt, $name) => stripos($name, $skill) !== false
                )->sum();
            }
        }

        $assignedCount = $leads->where('pool_status', PoolStatus::ASSIGNED->value)->count();
        $calledToday = $todayCycles->where('call_count', '>', 0)->count();
        $soldToday = $todayCycles->where('outcome', 'ORDERED')->count();

        return Inertia::render('AgentLeads/Index', [
            'leads' => AgentLeadResource::collection($leads)->resolve(),
            'stats' => [
                'assigned' => $assignedCount,
                'called_today' => $calledToday,
                'sold_today' => $soldToday,
                'callbacks_due' => $callbacksToday->count(),
                'conversion_rate' => $todayCycles->count() > 0
                    ? round($soldToday / $todayCycles->count() * 100, 1)
                    : 0,
            ],
            'poolStats' => $this->poolService->getPoolStats(),
            'filters' => $filters,
            'callbacksToday' => AgentLeadResource::collection($callbacksToday)->resolve(),
            'productSkills' => $productSkills,
            'matchingInPool' => $matchingInPool,
        ]);
    }

    public function requestLeads(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'count' => ['sometimes', 'integer', 'min:1', 'max:10'],
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
        $requestedProduct = $validated['product'] ?? null;

        // RESTRICTED SELF-PULL: Only pull from approved pool members.
        // Agents can no longer self-pull arbitrary leads from the global pool.
        $poolMembers = $this->poolReservationService->getPendingMembersForAgent($agent);

        if ($requestedProduct) {
            // Filter by explicit product request
            $poolMembers = $poolMembers->filter(function ($member) use ($requestedProduct) {
                return stripos($member->lead->product_name ?? '', $requestedProduct) !== false;
            })->values();
        } elseif (! empty($productSkills)) {
            // Filter by agent's product skills
            $poolMembers = $poolMembers->filter(function ($member) use ($productSkills) {
                $productName = $member->lead->product_name ?? '';
                foreach ($productSkills as $skill) {
                    if (stripos($productName, $skill) !== false) {
                        return true;
                    }
                }

                return false;
            })->values();
        }

        $poolMembers = $poolMembers->take($toAssign);

        if ($poolMembers->isEmpty()) {
            return response()->json([
                'message' => 'No approved leads are currently available for your team. Please check back later.',
                'assigned' => 0,
            ]);
        }

        // Distribute the leads from pool members
        $leadIds = $poolMembers->pluck('lead_id')->toArray();
        $result = $this->distributionService->distributeCustom(
            $leadIds,
            [$agent->id => count($leadIds)],
            $agent->id
        );

        // Mark pool members as assigned
        foreach ($poolMembers as $member) {
            if (in_array($member->lead_id, $leadIds)) {
                $this->poolReservationService->markMemberAssigned($member);
            }
        }

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
            // Order customization fields (only used when outcome = ORDERED)
            'quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
            'variant_id' => ['nullable', 'integer'],
            'custom_product_name' => ['nullable', 'string', 'max:255'],
            'custom_unit_price' => ['nullable', 'numeric', 'min:0'],
            'receiver_name' => ['nullable', 'string', 'max:200'],
            'receiver_phone' => ['nullable', 'string', 'max:20'],
            'receiver_address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:200'],
            'state' => ['nullable', 'string', 'max:200'],
            'barangay' => ['nullable', 'string', 'max:200'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'landmark' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'promo_ids' => ['nullable', 'array'],
            'promo_ids.*' => ['integer'],
        ]);

        $cycle = $lead->cycles()
            ->where('assigned_agent_id', auth()->id())
            ->where('status', 'ACTIVE')
            ->firstOrFail();

        $outcome = LeadOutcome::from($validated['outcome']);
        $callbackAt = isset($validated['callback_at']) ? new \DateTime($validated['callback_at']) : null;

        // Build order customization data if outcome is ORDERED
        $orderData = null;
        if ($outcome === LeadOutcome::ORDERED) {
            $orderData = array_filter([
                'quantity' => $validated['quantity'] ?? 1,
                'variant_id' => $validated['variant_id'] ?? null,
                'custom_product_name' => $validated['custom_product_name'] ?? null,
                'custom_unit_price' => isset($validated['custom_unit_price']) ? (float) $validated['custom_unit_price'] : null,
                'receiver_name' => $validated['receiver_name'] ?? null,
                'receiver_phone' => $validated['receiver_phone'] ?? null,
                'receiver_address' => $validated['receiver_address'] ?? null,
                'city' => $validated['city'] ?? null,
                'state' => $validated['state'] ?? null,
                'barangay' => $validated['barangay'] ?? null,
                'postal_code' => $validated['postal_code'] ?? null,
                'landmark' => $validated['landmark'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'promo_ids' => $validated['promo_ids'] ?? [],
            ], fn ($v) => $v !== null && $v !== '');
        }

        $this->recyclingService->processOutcome(
            $lead,
            $cycle,
            $outcome,
            auth()->user(),
            $validated['remarks'] ?? null,
            $callbackAt,
            $orderData
        );

        return response()->json([
            'message' => 'Outcome recorded',
            'lead' => new AgentLeadResource($lead->fresh(['customer', 'cycles'])),
        ]);
    }

    /**
     * Customer history lookup — only accessible if the agent is assigned to this lead.
     * Returns the customer's profile + their full waybill/order history (by phone match).
     * Agents cannot browse waybills directly; this is scoped to one specific customer.
     */
    public function customerHistory(Lead $lead): JsonResponse
    {
        if ($lead->assigned_to !== auth()->id()) {
            abort(403, 'You are not assigned to this lead.');
        }

        $lead->load('customer');
        $customer = $lead->customer;

        if (! $customer) {
            return response()->json([
                'customer' => null,
                'waybills' => [],
                'message' => 'No customer profile linked to this lead.',
            ]);
        }

        // Fetch waybill history for this customer via phone number match
        $waybills = Waybill::where('receiver_phone', $customer->phone)
            ->select([
                'id',
                'waybill_number',
                'status',
                'item_name',
                'cod_amount',
                'amount',
                'city',
                'state',
                'barangay',
                'receiver_address',
                'rts_reason',
                'delivered_at',
                'returned_at',
                'created_at',
            ])
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(fn ($w) => [
                'id' => $w->id,
                'waybill_number' => $w->waybill_number,
                'status' => $w->status,
                'item_name' => $w->item_name,
                'amount' => $w->cod_amount ?? $w->amount,
                'city' => $w->city,
                'state' => $w->state,
                'barangay' => $w->barangay,
                'address' => $w->receiver_address,
                'rts_reason' => $w->rts_reason,
                'delivered_at' => $w->delivered_at,
                'returned_at' => $w->returned_at,
                'created_at' => $w->created_at,
            ]);

        return response()->json([
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'canonical_address' => $customer->canonical_address,
                'total_orders' => $customer->total_orders,
                'successful_orders' => $customer->successful_orders,
                'returned_orders' => $customer->returned_orders,
                'success_rate' => $customer->success_rate,
                'total_revenue' => $customer->total_revenue,
                'risk_level' => $customer->risk_level,
                'is_blacklisted' => $customer->is_blacklisted,
                'blacklist_reason' => $customer->blacklist_reason,
            ],
            'waybills' => $waybills,
        ]);
    }

    /**
     * Agent waybill tracking — search by tracking number, customer name, or phone.
     */
    public function tracking(Request $request): Response
    {
        $search = trim($request->input('search', ''));
        $waybills = collect();
        $selectedWaybill = null;

        if (! empty($search)) {
            // Search by tracking number, receiver name, or phone
            $query = Waybill::query()
                ->where(function ($q) use ($search) {
                    $q->where('waybill_number', 'ILIKE', "%{$search}%")
                        ->orWhere('receiver_name', 'ILIKE', "%{$search}%")
                        ->orWhere('receiver_phone', 'ILIKE', "%{$search}%");
                })
                ->orderBy('created_at', 'desc')
                ->limit(20);

            $waybills = $query->get();

            // If viewing a specific waybill
            $viewId = $request->input('view');
            if ($viewId) {
                $selectedWaybill = Waybill::with('trackingHistory')
                    ->find($viewId);
            } elseif ($waybills->count() === 1) {
                // Auto-select if only one result
                $selectedWaybill = $waybills->first();
                $selectedWaybill->load('trackingHistory');
            }
        }

        // Build customer context if viewing a specific waybill
        $customerData = null;
        $orderHistory = [];
        if ($selectedWaybill) {
            $phone = $selectedWaybill->receiver_phone;

            // Get all orders for this customer (by phone)
            $allOrders = Waybill::where('receiver_phone', $phone)
                ->orderBy('created_at', 'desc')
                ->limit(20)
                ->get(['id', 'waybill_number', 'status', 'item_name', 'cod_amount', 'amount', 'delivered_at', 'returned_at', 'created_at']);

            $orderHistory = $allOrders->map(fn ($w) => [
                'id' => $w->id,
                'waybill_number' => $w->waybill_number,
                'status' => $w->status,
                'item_name' => $w->item_name,
                'amount' => $w->cod_amount ?? $w->amount,
                'delivered_at' => $w->delivered_at,
                'returned_at' => $w->returned_at,
                'created_at' => $w->created_at,
                'is_current' => $w->id === $selectedWaybill->id,
            ])->toArray();

            $totalOrders = $allOrders->count();
            $delivered = $allOrders->where('status', 'DELIVERED')->count();
            $returned = $allOrders->where('status', 'RETURNED')->count();

            $customerData = [
                'total_orders' => $totalOrders,
                'delivered' => $delivered,
                'returned' => $returned,
                'pending' => $totalOrders - $delivered - $returned,
                'success_rate' => $totalOrders > 0 ? round(($delivered / $totalOrders) * 100, 1) : 0,
                'total_cod' => (float) $allOrders->sum(fn ($w) => $w->cod_amount ?? $w->amount),
                'risk_label' => match (true) {
                    $totalOrders === 0 => 'New',
                    $totalOrders > 0 && $delivered === 0 && $returned > 0 => 'High Risk',
                    $totalOrders > 2 && ($returned / $totalOrders) > 0.5 => 'High Risk',
                    $totalOrders > 2 && ($delivered / $totalOrders) >= 0.75 => 'Reliable',
                    default => 'Normal',
                },
            ];
        }

        return Inertia::render('AgentLeads/Tracking', [
            'results' => $waybills->map(fn ($w) => [
                'id' => $w->id,
                'waybill_number' => $w->waybill_number,
                'status' => $w->status,
                'courier_provider' => $w->courier_provider,
                'receiver_name' => $w->receiver_name,
                'receiver_phone' => substr($w->receiver_phone, 0, 4).'****'.substr($w->receiver_phone, -3),
                'city' => $w->city,
                'state' => $w->state,
                'item_name' => $w->item_name,
                'cod_amount' => $w->cod_amount,
                'created_at' => $w->created_at,
            ]),
            'waybill' => $selectedWaybill ? [
                'id' => $selectedWaybill->id,
                'waybill_number' => $selectedWaybill->waybill_number,
                'status' => $selectedWaybill->status,
                'courier_provider' => $selectedWaybill->courier_provider,
                'receiver_name' => $selectedWaybill->receiver_name,
                'receiver_phone' => substr($selectedWaybill->receiver_phone, 0, 4).'****'.substr($selectedWaybill->receiver_phone, -3),
                'city' => $selectedWaybill->city,
                'state' => $selectedWaybill->state,
                'item_name' => $selectedWaybill->item_name,
                'cod_amount' => $selectedWaybill->cod_amount,
                'submitted_at' => $selectedWaybill->submitted_at,
                'signed_at' => $selectedWaybill->signed_at,
                'dispatched_at' => $selectedWaybill->dispatched_at,
                'delivered_at' => $selectedWaybill->delivered_at,
                'returned_at' => $selectedWaybill->returned_at,
                'created_at' => $selectedWaybill->created_at,
                'tracking_history' => $selectedWaybill->trackingHistory->map(fn ($h) => [
                    'status' => $h->status,
                    'previous_status' => $h->previous_status,
                    'reason' => $h->reason,
                    'location' => $h->location,
                    'tracked_at' => $h->tracked_at,
                ]),
            ] : null,
            'customer' => $customerData,
            'orderHistory' => $orderHistory,
            'search' => $search,
            'notFound' => ! empty($search) && $waybills->isEmpty(),
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

    /**
     * Return unread lead count for the authenticated agent.
     * Used by polling-based notification badge.
     */
    public function unreadCount(): JsonResponse
    {
        $agentId = auth()->id();

        $count = Lead::where('assigned_to', $agentId)
            ->where('pool_status', PoolStatus::ASSIGNED)
            ->where('assigned_at', '>=', now()->subHours(24))
            ->count();

        $latest = Lead::where('assigned_to', $agentId)
            ->where('pool_status', PoolStatus::ASSIGNED)
            ->latest('assigned_at')
            ->first();

        return response()->json([
            'count' => $count,
            'latest' => $latest ? [
                'lead_id' => $latest->id,
                'customer_name' => $latest->name,
                'product' => $latest->product_name,
                'province' => $latest->state,
                'city' => $latest->city,
                'priority' => $latest->quality_score ?? 50,
            ] : null,
        ]);
    }

    public function heartbeat(): JsonResponse
    {
        $agent = auth()->user();
        $profile = $agent->agentProfile()->firstOrCreate(
            ['user_id' => $agent->id],
            ['is_available' => true]
        );

        $wasAutoUnavailable = ! $profile->is_available;

        $profile->forceFill([
            'last_seen_at' => now(),
            'is_available' => true,
        ])->save();

        $threshold = $profile->idle_threshold_minutes ?? 15;

        return response()->json([
            'is_available' => true,
            'last_seen_at' => $profile->last_seen_at->toIso8601String(),
            'idle_threshold_minutes' => $threshold,
            'restored' => $wasAutoUnavailable,
        ]);
    }

    public function toggleAvailability(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'is_available' => ['required', 'boolean'],
        ]);

        $agent = auth()->user();
        $profile = $agent->agentProfile()->firstOrCreate(
            ['user_id' => $agent->id],
            ['is_available' => true]
        );

        $profile->forceFill([
            'is_available' => $validated['is_available'],
            'last_seen_at' => now(),
        ])->save();

        $threshold = $profile->idle_threshold_minutes ?? 15;

        return response()->json([
            'is_available' => $profile->is_available,
            'last_seen_at' => $profile->last_seen_at->toIso8601String(),
            'idle_threshold_minutes' => $threshold,
        ]);
    }

    public function availabilityStatus(): JsonResponse
    {
        $agent = auth()->user();
        $profile = $agent->agentProfile;

        if (! $profile) {
            return response()->json([
                'is_available' => false,
                'last_seen_at' => null,
                'idle_threshold_minutes' => 15,
                'idle_minutes' => null,
                'remaining_minutes' => null,
                'shift_start' => null,
                'shift_end' => null,
                'in_shift' => true,
            ]);
        }

        $threshold = $profile->idle_threshold_minutes ?? 15;
        $idleMinutes = $profile->last_seen_at
            ? (int) $profile->last_seen_at->diffInMinutes(now())
            : null;
        $remainingMinutes = $idleMinutes !== null
            ? max(0, $threshold - $idleMinutes)
            : null;

        $inShift = $this->isInShift($profile->shift_start, $profile->shift_end);

        return response()->json([
            'is_available' => $profile->is_available,
            'last_seen_at' => $profile->last_seen_at?->toIso8601String(),
            'idle_threshold_minutes' => $threshold,
            'idle_minutes' => $idleMinutes,
            'remaining_minutes' => $remainingMinutes,
            'shift_start' => $profile->shift_start,
            'shift_end' => $profile->shift_end,
            'in_shift' => $inShift,
        ]);
    }

    private function isInShift(?string $shiftStart, ?string $shiftEnd): bool
    {
        if (! $shiftStart || ! $shiftEnd) {
            return true;
        }

        $nowTime = now()->format('H:i');
        $startTime = now()->parse($shiftStart)->format('H:i');
        $endTime = now()->parse($shiftEnd)->format('H:i');

        if ($endTime < $startTime) {
            return $nowTime >= $startTime || $nowTime < $endTime;
        }

        return $nowTime >= $startTime && $nowTime < $endTime;
    }

    public function gamification(): Response
    {
        $agent = auth()->user();

        $profile = $this->gamificationService->getAgentProfile($agent->id);
        $leaderboard = $this->gamificationService->getLeaderboard(10);
        $settings = $this->gamificationService->getSettings();

        return Inertia::render('AgentLeads/Gamification', [
            'profile' => $profile,
            'leaderboard' => $leaderboard,
            'settings' => $settings,
            'agent' => [
                'id' => $agent->id,
                'name' => $agent->name,
            ],
        ]);
    }

    public function pwaSettings(): Response
    {
        return Inertia::render('AgentLeads/PWASettings');
    }

    /**
     * API: Get delivery ETA for a given address.
     */
    public function deliveryEta(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'province' => ['nullable', 'string', 'max:200'],
            'city' => ['nullable', 'string', 'max:200'],
            'barangay' => ['nullable', 'string', 'max:200'],
        ]);

        $eta = $this->etaService->estimateEta(
            $validated['province'] ?? null,
            $validated['city'] ?? null,
            $validated['barangay'] ?? null,
        );

        return response()->json($eta);
    }

    /**
     * API: Get weather forecast for a given location (or default Manila).
     */
    public function weather(Request $request): JsonResponse
    {
        $lat = (float) $request->input('lat', 14.5995);
        $lon = (float) $request->input('lon', 120.9842);
        $city = (string) $request->input('city', 'Manila, PH');

        $weather = $this->etaService->getWeatherForecast($lat, $lon, $city);

        return response()->json($weather);
    }

    /**
     * API: Get list of provinces from shipping_days table.
     */
    public function addressProvinces(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('q', ''));

        $query = ShippingDay::query()
            ->select('province')
            ->distinct();

        if ($search !== '') {
            $normalized = ShippingDay::normalize($search);
            $query->where('province', 'ILIKE', "%{$normalized}%");
        }

        $provinces = $query->orderBy('province')
            ->limit(100)
            ->pluck('province')
            ->map(fn ($p) => ucwords(strtolower(str_replace('-', ' ', $p))))
            ->values();

        return response()->json(['provinces' => $provinces]);
    }

    /**
     * API: Get list of cities for a given province.
     */
    public function addressCities(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'province' => ['required', 'string', 'max:200'],
            'q' => ['nullable', 'string', 'max:200'],
        ]);

        $normalizedProvince = ShippingDay::normalize($validated['province']);
        $search = trim((string) ($validated['q'] ?? ''));

        $query = ShippingDay::query()
            ->select('city')
            ->where('province', $normalizedProvince)
            ->distinct();

        if ($search !== '') {
            $normalizedCity = ShippingDay::normalize($search);
            $query->where('city', 'ILIKE', "%{$normalizedCity}%");
        }

        $cities = $query->orderBy('city')
            ->limit(200)
            ->pluck('city')
            ->map(fn ($c) => ucwords(strtolower(str_replace('-', ' ', $c))))
            ->values();

        return response()->json(['cities' => $cities]);
    }

    /**
     * API: Get list of barangays for a given province + city.
     */
    public function addressBarangays(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'province' => ['required', 'string', 'max:200'],
            'city' => ['required', 'string', 'max:200'],
            'q' => ['nullable', 'string', 'max:200'],
        ]);

        $normalizedProvince = ShippingDay::normalize($validated['province']);
        $normalizedCity = ShippingDay::normalize($validated['city']);
        $search = trim((string) ($validated['q'] ?? ''));

        $query = ShippingDay::query()
            ->select('barangay', 'shipping_days')
            ->where('province', $normalizedProvince)
            ->where('city', $normalizedCity)
            ->whereNotNull('barangay');

        if ($search !== '') {
            $query->where('barangay', 'ILIKE', '%'.mb_strtoupper(trim($search)).'%');
        }

        $barangays = $query->orderBy('barangay')
            ->limit(500)
            ->get()
            ->map(fn ($row) => [
                'name' => ucwords(strtolower($row->barangay)),
                'shipping_days' => $row->shipping_days,
            ])
            ->values();

        return response()->json(['barangays' => $barangays]);
    }
}
