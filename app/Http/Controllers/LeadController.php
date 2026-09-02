<?php

namespace App\Http\Controllers;

use Modules\Leads\Enums\LeadStatus;
use Modules\Leads\Enums\PoolStatus;
use Modules\Leads\Models\Lead;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Models\User;
use App\Services\LeadLifecycleService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LeadController extends Controller
{
    public function __construct(
        private LeadLifecycleService $lifecycleService,
    ) {}

    public function index(Request $request)
    {
        $query = Lead::with('assignedAgent');

        // Non-supervisors should not see leads still in the distribution pool
        if (! in_array(auth()->user()->role ?? '', ['superadmin', 'admin', 'supervisor'])) {
            $query->where('pool_status', '!=', PoolStatus::AVAILABLE);
        }

        // Apply filters
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ILIKE', "%{$search}%")
                    ->orWhere('phone', 'ILIKE', "%{$search}%")
                    ->orWhere('address', 'ILIKE', "%{$search}%");
            });
        }

        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        if ($request->has('sales_status') && $request->sales_status) {
            $query->where('sales_status', $request->sales_status);
        }

        if ($request->has('pool_status') && $request->pool_status) {
            $query->where('pool_status', $request->pool_status);
        }

        if ($request->has('assigned') && $request->assigned) {
            if ($request->assigned === 'unassigned') {
                $query->whereNull('assigned_to');
            } else {
                $query->where('assigned_to', $request->assigned);
            }
        }

        // Get paginated results
        $leads = $query->orderBy('created_at', 'desc')
            ->paginate(20)
            ->withQueryString();

        // Calculate stats
        $total = Lead::count();
        $converted = Lead::where('status', LeadStatus::SALE)->count();
        $stats = [
            'total' => $total,
            'new' => Lead::where('status', LeadStatus::NEW)->count(),
            'in_progress' => Lead::whereIn('status', [LeadStatus::CALLING, LeadStatus::CALLBACK])->count(),
            'converted' => $converted,
            'conversion_rate' => $total > 0 ? round(($converted / $total) * 100, 1) : 0,
        ];

        return Inertia::render('Leads/Index', [
            'leads' => $leads,
            'filters' => $request->only(['search', 'status', 'sales_status', 'assigned']),
            'stats' => $stats,
        ]);
    }

    public function show(Lead $lead)
    {
        $lead->load(['assignedAgent', 'customer', 'cycles.assignedAgent', 'uploader', 'waybills']);

        $lifecycle = $this->lifecycleService->getLifecycle($lead);

        return Inertia::render('Leads/Show', [
            'lead' => $lead,
            'lifecycle' => $lifecycle,
        ]);
    }

    public function lifecycle(Lead $lead)
    {
        $lifecycle = $this->lifecycleService->getLifecycle($lead);

        return response()->json($lifecycle);
    }

    public function qcIndex()
    {
        $queue = Order::with(['product', 'agent', 'customer', 'lead'])
            ->where('status', OrderStatus::QA_PENDING)
            ->orderBy('created_at', 'asc')
            ->get();

        $stats = [
            'pending' => Order::where('status', OrderStatus::QA_PENDING)->count(),
            'approved_today' => Order::where('status', OrderStatus::QA_APPROVED)
                ->whereDate('confirmed_at', today())
                ->count(),
            'rejected_today' => Order::where('status', OrderStatus::QA_REJECTED)
                ->whereDate('updated_at', today())
                ->count(),
            'total_today' => Order::whereDate('created_at', today())->count(),
        ];

        return Inertia::render('QC/Index', [
            'queue' => $queue,
            'stats' => $stats,
        ]);
    }

    public function recyclingPool()
    {
        $leads = Lead::whereNotIn('pool_status', [PoolStatus::ASSIGNED, PoolStatus::EXHAUSTED])
            ->whereIn('status', [LeadStatus::NO_ANSWER, LeadStatus::CALLBACK])
            ->orderBy('updated_at', 'asc')
            ->get()
            ->map(function ($lead) {
                $lead->days_in_pool = now()->diffInDays($lead->updated_at);

                return $lead;
            });

        $agents = User::where('role', 'agent')
            ->where('is_active', true)
            ->get();

        $stats = [
            'pool_size' => $leads->count(),
            'recycled_today' => $leads->where('status', LeadStatus::NO_ANSWER)
                ->filter(fn ($l) => Carbon::parse($l->updated_at)->isToday())
                ->count(),
            'avg_days_in_pool' => round((float) ($leads->avg('days_in_pool') ?? 0), 1),
            'reassigned_today' => 0,
        ];

        return Inertia::render('Recycling/Index', [
            'leads' => $leads,
            'agents' => $agents,
            'stats' => $stats,
        ]);
    }
}
