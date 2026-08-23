<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Lead\Models\LeadPoolRequest;
use App\Services\PoolRequestService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PoolApprovalController extends Controller
{
    public function __construct(
        private PoolRequestService $requestService
    ) {
        $this->middleware(function ($request, $next) {
            if (! in_array(auth()->user()->role, ['superadmin', 'admin'])) {
                abort(403, 'Pool approval requires admin role.');
            }

            return $next($request);
        });
    }

    /**
     * Show the approval queue — all pending pool requests.
     */
    public function index(): Response
    {
        $requests = LeadPoolRequest::with(['requestedBy', 'pool'])
            ->pending()
            ->orderBy('created_at')
            ->paginate(20);

        // Attach live availability for each request
        $requests->getCollection()->transform(function (LeadPoolRequest $request) {
            $request->setAttribute('current_available', $this->requestService->recalculateAvailability($request));

            return $request;
        });

        return Inertia::render('Telesales/PoolApprovals/Index', [
            'requests' => $requests,
        ]);
    }

    /**
     * Show a single request for approval with full details.
     */
    public function show(LeadPoolRequest $poolRequest): Response
    {
        $poolRequest->load(['requestedBy', 'pool.members']);

        $currentAvailable = $this->requestService->recalculateAvailability($poolRequest);

        return Inertia::render('Telesales/PoolApprovals/Show', [
            'poolRequest' => $poolRequest,
            'currentAvailable' => $currentAvailable,
            'canFulfill' => $currentAvailable >= $poolRequest->requested_quantity,
        ]);
    }

    /**
     * Approve a pool request (optionally with modified quantity).
     */
    public function approve(LeadPoolRequest $poolRequest, Request $request)
    {
        $validated = $request->validate([
            'approved_quantity' => ['nullable', 'integer', 'min:1', 'max:50000'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $result = $this->requestService->approveRequest(
                $poolRequest,
                $request->user(),
                $validated['approved_quantity'] ?? null,
                $validated['notes'] ?? null
            );

            return redirect()
                ->route('telesales.pools.show', $result['pool'])
                ->with('success', "Request {$poolRequest->request_number} approved. {$result['reserved']} leads reserved into pool {$result['pool']->pool_number}.");
        } catch (\RuntimeException $e) {
            return redirect()
                ->back()
                ->with('error', $e->getMessage());
        }
    }

    /**
     * Reject a pool request.
     */
    public function reject(LeadPoolRequest $poolRequest, Request $request)
    {
        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:1000'],
        ]);

        $this->requestService->rejectRequest($poolRequest, $request->user(), $validated['rejection_reason']);

        return redirect()
            ->route('telesales.pool-approvals.index')
            ->with('success', "Request {$poolRequest->request_number} rejected.");
    }
}
