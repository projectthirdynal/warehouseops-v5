<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Lead\Enums\PoolRequestStatus;
use App\Domain\Lead\Models\LeadPoolRequest;
use App\Services\LeadEligibilityService;
use App\Services\PoolRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PoolRequestController extends Controller
{
    public function __construct(
        private PoolRequestService $requestService,
        private LeadEligibilityService $eligibilityService
    ) {
        $this->middleware(function ($request, $next) {
            if (! in_array(auth()->user()->role, ['superadmin', 'admin', 'supervisor', 'teamleader'])) {
                abort(403, 'Pool request access requires supervisor or admin role.');
            }

            return $next($request);
        });
    }

    /**
     * List all pool requests (admin sees all, supervisor sees their own).
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $statusFilter = $request->input('status', 'all');

        $query = LeadPoolRequest::with(['requestedBy', 'approvedBy', 'rejectedBy', 'pool']);

        if (in_array($user->role, ['supervisor', 'teamleader'])) {
            $query->where('requested_by', $user->id);
        }

        if ($statusFilter !== 'all') {
            $query->where('status', $statusFilter);
        }

        $requests = $query->orderByDesc('created_at')->paginate(20);

        return Inertia::render('Telesales/PoolRequests/Index', [
            'requests' => $requests,
            'statusFilter' => $statusFilter,
            'statusOptions' => collect(PoolRequestStatus::cases())->map(fn ($s) => [
                'value' => $s->value,
                'label' => $s->label(),
            ]),
        ]);
    }

    /**
     * Show the create pool request form.
     */
    public function create(): Response
    {
        $filterOptions = $this->eligibilityService->getFilterOptions();

        return Inertia::render('Telesales/PoolRequests/Create', [
            'filterOptions' => $filterOptions,
        ]);
    }

    /**
     * Store a new pool request.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'brand_name' => ['required', 'string', 'max:100'],
            'product_name' => ['nullable', 'string', 'max:255'],
            'business_region' => ['nullable', 'string', 'max:50'],
            'province' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'lead_age_from' => ['nullable', 'integer', 'min:0', 'max:365'],
            'lead_age_to' => ['nullable', 'integer', 'min:1', 'max:365'],
            'requested_quantity' => ['required', 'integer', 'min:1', 'max:50000'],
            'distribution_method' => ['nullable', 'string', 'in:equal,manual_quantity,round_robin'],
            'team_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $poolRequest = $this->requestService->createRequest($validated, $request->user());

        return redirect()
            ->route('telesales.pool-requests.show', $poolRequest)
            ->with('success', "Pool request {$poolRequest->request_number} submitted for approval.");
    }

    /**
     * Show a specific pool request with live availability recalculation.
     */
    public function show(LeadPoolRequest $poolRequest, Request $request): Response
    {
        $poolRequest->load(['requestedBy', 'approvedBy', 'rejectedBy', 'pool.members']);

        $currentAvailable = $this->requestService->recalculateAvailability($poolRequest);

        return Inertia::render('Telesales/PoolRequests/Show', [
            'poolRequest' => $poolRequest,
            'currentAvailable' => $currentAvailable,
            'canApprove' => in_array($request->user()->role, ['superadmin', 'admin']),
        ]);
    }

    /**
     * Cancel a pool request (requester or admin only).
     */
    public function cancel(LeadPoolRequest $poolRequest, Request $request)
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $this->requestService->cancelRequest($poolRequest, $request->user(), $validated['reason'] ?? null);

        return redirect()
            ->route('telesales.pool-requests.index')
            ->with('success', "Request {$poolRequest->request_number} cancelled.");
    }

    /**
     * API: Get live eligible count for the given filters (used by the create form).
     */
    public function countEligible(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'brand' => ['nullable', 'string'],
            'product' => ['nullable', 'string'],
            'business_region' => ['nullable', 'string'],
            'province' => ['nullable', 'string'],
            'city' => ['nullable', 'string'],
            'age_from' => ['nullable', 'integer', 'min:0'],
            'age_to' => ['nullable', 'integer', 'min:1'],
            'source' => ['nullable', 'string'],
        ]);

        $count = $this->eligibilityService->countEligible($filters);

        return response()->json([
            'count' => $count,
            'filters' => $filters,
        ]);
    }
}
