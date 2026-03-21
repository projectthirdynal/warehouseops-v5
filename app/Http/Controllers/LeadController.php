<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LeadController extends Controller
{
    public function index(Request $request)
    {
        $query = Lead::with('assignedAgent');

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
        $stats = [
            'total' => Lead::count(),
            'new' => Lead::where('status', 'NEW')->count(),
            'in_progress' => Lead::whereIn('status', ['CALLING', 'CALLBACK'])->count(),
            'converted' => Lead::where('status', 'SALE')->count(),
            'conversion_rate' => Lead::count() > 0
                ? round((Lead::where('status', 'SALE')->count() / Lead::count()) * 100, 1)
                : 0,
        ];

        return Inertia::render('Leads/Index', [
            'leads' => $leads,
            'filters' => $request->only(['search', 'status', 'sales_status', 'assigned']),
            'stats' => $stats,
        ]);
    }

    public function show(Lead $lead)
    {
        $lead->load(['assignedAgent', 'customer', 'cycles.assignedAgent']);

        return Inertia::render('Leads/Show', [
            'lead' => $lead,
        ]);
    }

    public function qcIndex()
    {
        $queue = Lead::with(['customer'])
            ->where('sales_status', 'QA_PENDING')
            ->orderBy('created_at', 'asc')
            ->get();

        $stats = [
            'pending' => Lead::where('sales_status', 'QA_PENDING')->count(),
            'approved_today' => Lead::where('sales_status', 'QA_APPROVED')
                ->whereDate('updated_at', today())
                ->count(),
            'rejected_today' => Lead::where('sales_status', 'QA_REJECTED')
                ->whereDate('updated_at', today())
                ->count(),
            'avg_review_time' => '3m',
        ];

        return Inertia::render('QC/Index', [
            'queue' => $queue,
            'stats' => $stats,
        ]);
    }

    public function recyclingPool()
    {
        $leads = Lead::whereNull('assigned_to')
            ->orWhereIn('status', ['NO_ANSWER', 'CALLBACK'])
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
            'recycled_today' => Lead::whereDate('updated_at', today())
                ->whereIn('status', ['NO_ANSWER'])
                ->count(),
            'avg_days_in_pool' => $leads->avg('days_in_pool') ?? 0,
            'reassigned_today' => 0,
        ];

        return Inertia::render('Recycling/Index', [
            'leads' => $leads,
            'agents' => $agents,
            'stats' => $stats,
        ]);
    }
}
