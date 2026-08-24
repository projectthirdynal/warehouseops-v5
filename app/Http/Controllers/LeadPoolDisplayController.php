<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Lead\Enums\LeadPoolStatus;
use App\Domain\Lead\Models\LeadPool;
use App\Services\PoolReservationService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LeadPoolDisplayController extends Controller
{
    public function __construct(
        private PoolReservationService $reservationService
    ) {
        $this->middleware(function ($request, $next) {
            if (! in_array(auth()->user()->role, ['superadmin', 'admin', 'supervisor', 'teamleader'])) {
                abort(403, 'Pool access requires supervisor or admin role.');
            }

            return $next($request);
        });
    }

    /**
     * List all approved lead pools.
     */
    public function index(Request $request): Response
    {
        $statusFilter = $request->input('status', 'all');

        $query = LeadPool::with(['request.requestedBy', 'createdBy', 'approvedBy']);

        if ($statusFilter !== 'all') {
            $query->where('status', $statusFilter);
        }

        $pools = $query->orderByDesc('created_at')->paginate(20);

        return Inertia::render('Telesales/LeadPools/Index', [
            'pools' => $pools,
            'statusFilter' => $statusFilter,
            'statusOptions' => collect(LeadPoolStatus::cases())->map(fn ($s) => [
                'value' => $s->value,
                'label' => $s->label(),
            ]),
        ]);
    }

    /**
     * Show a specific pool with its members.
     */
    public function show(LeadPool $pool, Request $request): Response
    {
        $pool->load(['request.requestedBy', 'createdBy', 'approvedBy']);

        $members = $pool->members()
            ->with(['lead.customer'])
            ->orderBy('added_at')
            ->paginate(50);

        return Inertia::render('Telesales/LeadPools/Show', [
            'pool' => $pool,
            'members' => $members,
        ]);
    }

    /**
     * Cancel a pool (admin only).
     */
    public function cancel(LeadPool $pool, Request $request)
    {
        if (! in_array($request->user()->role, ['superadmin', 'admin'])) {
            abort(403, 'Only admins can cancel pools.');
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $this->reservationService->cancelPool($pool, $request->user(), $validated['reason'] ?? null);

        return redirect()
            ->route('telesales.pools.index')
            ->with('success', "Pool {$pool->pool_number} cancelled.");
    }
}
