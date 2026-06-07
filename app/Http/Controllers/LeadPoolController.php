<?php

namespace App\Http\Controllers;

use App\Domain\Lead\Enums\LeadSource;
use App\Domain\Lead\Enums\PoolStatus;
use App\Domain\Lead\Models\Lead;
use App\Http\Resources\LeadPoolResource;
use App\Models\LeadCycle;
use App\Models\User;
use App\Services\LeadDistributionService;
use App\Services\LeadPoolService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LeadPoolController extends Controller
{
    public function __construct(
        private LeadPoolService $poolService,
        private LeadDistributionService $distributionService
    ) {
        $this->middleware(function ($request, $next) {
            if (!in_array(auth()->user()->role, ['superadmin', 'admin', 'supervisor'])) {
                abort(403, 'Supervisors only');
            }
            return $next($request);
        });
    }

    public function index(Request $request): Response
    {
        $filters = $request->only(['source', 'city', 'product_name', 'pool_status']);
        $viewMode = $request->input('view_mode', 'pool');

        $query = Lead::with(['assignedAgent', 'customer']);

        // View mode determines base query scope
        if ($viewMode === 'pool') {
            if (isset($filters['pool_status']) && $filters['pool_status'] !== 'all') {
                $query->where('pool_status', $filters['pool_status']);
            } else {
                $query->where('pool_status', PoolStatus::AVAILABLE);
            }
        } elseif ($viewMode === 'imported') {
            $query->whereIn('source', [LeadSource::TELESALES_IMPORT, LeadSource::XLSX_IMPORT]);
            if (isset($filters['pool_status']) && $filters['pool_status'] !== 'all') {
                $query->where('pool_status', $filters['pool_status']);
            }
        } else {
            // 'all' — no pool_status restriction
            if (isset($filters['pool_status']) && $filters['pool_status'] !== 'all') {
                $query->where('pool_status', $filters['pool_status']);
            }
        }

        if (isset($filters['source'])) {
            $query->where('source', $filters['source']);
        }
        if (isset($filters['city'])) {
            $query->where('city', 'ILIKE', "%{$filters['city']}%");
        }
        if (isset($filters['product_name'])) {
            $query->where('product_name', 'ILIKE', "%{$filters['product_name']}%");
        }

        $leads = $query->orderBy('created_at', 'asc')->paginate(50);

        $agents = $this->distributionService->getAvailableAgents();

        // Single query for all agent active lead counts (fixes N+1)
        $activeLeadCounts = Lead::whereIn('assigned_to', $agents->pluck('id'))
            ->where('pool_status', PoolStatus::ASSIGNED)
            ->selectRaw('assigned_to, count(*) as count')
            ->groupBy('assigned_to')
            ->pluck('count', 'assigned_to');

        // Stats depend on view mode
        if ($viewMode === 'pool') {
            $stats = \Illuminate\Support\Facades\Cache::remember('lead_pool:stats', 30, fn () =>
                $this->poolService->getPoolStats()
            );
        } elseif ($viewMode === 'imported') {
            $stats = [
                'total' => Lead::whereIn('source', [LeadSource::TELESALES_IMPORT, LeadSource::XLSX_IMPORT])->count(),
                'available' => Lead::whereIn('source', [LeadSource::TELESALES_IMPORT, LeadSource::XLSX_IMPORT])->where('pool_status', PoolStatus::AVAILABLE)->count(),
                'assigned' => Lead::whereIn('source', [LeadSource::TELESALES_IMPORT, LeadSource::XLSX_IMPORT])->where('pool_status', PoolStatus::ASSIGNED)->count(),
                'cooldown' => Lead::whereIn('source', [LeadSource::TELESALES_IMPORT, LeadSource::XLSX_IMPORT])->where('pool_status', PoolStatus::COOLDOWN)->count(),
            ];
        } else {
            $stats = [
                'total' => Lead::count(),
                'new' => Lead::where('status', 'NEW')->count(),
                'in_progress' => Lead::whereIn('status', ['CALLING', 'CALLBACK'])->count(),
                'converted' => Lead::where('status', 'SALE')->count(),
            ];
        }

        return Inertia::render('LeadPool/Index', [
            'leads' => LeadPoolResource::collection($leads),
            'stats' => $stats,
            'agents' => $agents->map(fn($agent) => [
                'id' => $agent->id,
                'name' => $agent->name,
                'active_leads' => $activeLeadCounts[$agent->id] ?? 0,
                'max_active_cycles' => $agent->agentProfile->max_active_cycles ?? 10,
            ]),
            'filters' => array_merge($filters, ['view_mode' => $viewMode]),
            'viewMode' => $viewMode,
            'sourceOptions' => collect(LeadSource::cases())->map(fn ($s) => [
                'value' => $s->value,
                'label' => $s->label(),
            ]),
        ]);
    }

    public function distribute(Request $request)
    {
        $validated = $request->validate([
            'lead_ids' => ['required', 'array', 'min:1'],
            'lead_ids.*' => ['integer', 'exists:leads,id'],
            'agent_ids' => ['required_if:method,equal', 'array'],
            'agent_ids.*' => ['integer', 'exists:users,id'],
            'distribution' => ['required_if:method,custom', 'array'],
            'method' => ['required', 'in:equal,custom'],
        ]);

        if ($validated['method'] === 'equal') {
            $result = $this->distributionService->distributeEqual(
                $validated['lead_ids'],
                $validated['agent_ids'],
                auth()->id()
            );
        } else {
            $result = $this->distributionService->distributeCustom(
                $validated['lead_ids'],
                $validated['distribution'],
                auth()->id()
            );
        }

        return redirect()->back()->with('success', "Distributed {$result['total_distributed']} leads to {$result['agent_count']} agents");
    }

    public function agentPerformance(): Response
    {
        $agents = User::where('role', 'agent')
            ->where('is_active', true)
            ->with('agentProfile')
            ->get()
            ->map(function ($agent) {
                $todayCycles = LeadCycle::where('assigned_agent_id', $agent->id)
                    ->whereDate('opened_at', today())
                    ->get();

                return [
                    'id' => $agent->id,
                    'name' => $agent->name,
                    'active_leads' => Lead::where('assigned_to', $agent->id)
                        ->where('pool_status', PoolStatus::ASSIGNED)->count(),
                    'called_today' => $todayCycles->where('call_count', '>', 0)->count(),
                    'sold_today' => $todayCycles->where('outcome', 'ORDERED')->count(),
                    'no_answer_today' => $todayCycles->where('outcome', 'NO_ANSWER')->count(),
                    'conversion_rate' => $todayCycles->count() > 0
                        ? round($todayCycles->where('outcome', 'ORDERED')->count() / $todayCycles->count() * 100, 1)
                        : 0,
                    'is_available' => $agent->agentProfile?->is_available ?? false,
                ];
            });

        return Inertia::render('LeadPool/AgentPerformance', [
            'agents' => $agents,
        ]);
    }
}
