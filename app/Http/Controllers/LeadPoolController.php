<?php

namespace App\Http\Controllers;

use App\Domain\Lead\Enums\LeadSource;
use App\Domain\Lead\Enums\LeadStatus;
use App\Domain\Lead\Enums\PoolStatus;
use App\Domain\Lead\Models\Lead;
use App\Http\Resources\LeadPoolResource;
use App\Models\LeadCycle;
use App\Models\User;
use App\Services\LeadDistributionService;
use App\Services\LeadPoolService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class LeadPoolController extends Controller
{
    public function __construct(
        private LeadPoolService $poolService,
        private LeadDistributionService $distributionService
    ) {
        $this->middleware(function ($request, $next) {
            if (! in_array(auth()->user()->role, ['superadmin', 'admin', 'supervisor'])) {
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
            $query->whereRaw('LOWER(city) LIKE ?', ['%'.mb_strtolower($filters['city']).'%']);
        }
        if (isset($filters['product_name'])) {
            $query->whereRaw('LOWER(product_name) LIKE ?', ['%'.mb_strtolower($filters['product_name']).'%']);
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
            $stats = Cache::remember('lead_pool:stats', 30, fn () => $this->poolService->getPoolStats()
            );
        } elseif ($viewMode === 'imported') {
            // 30-second cache matching the pool:stats invalidation pattern (ISS-015)
            $stats = Cache::remember('lead_pool:stats:imported', 30, function () {
                $sources = [LeadSource::TELESALES_IMPORT, LeadSource::XLSX_IMPORT];

                return [
                    'total' => Lead::whereIn('source', $sources)->count(),
                    'available' => Lead::whereIn('source', $sources)->where('pool_status', PoolStatus::AVAILABLE)->count(),
                    'assigned' => Lead::whereIn('source', $sources)->where('pool_status', PoolStatus::ASSIGNED)->count(),
                    'cooldown' => Lead::whereIn('source', $sources)->where('pool_status', PoolStatus::COOLDOWN)->count(),
                ];
            });
        } else {
            // 30-second cache matching the pool:stats invalidation pattern (ISS-015)
            $stats = Cache::remember('lead_pool:stats:all', 30, function () {
                return [
                    'total' => Lead::count(),
                    'new' => Lead::where('status', LeadStatus::NEW)->count(),
                    'in_progress' => Lead::whereIn('status', [LeadStatus::CALLING, LeadStatus::CALLBACK])->count(),
                    'converted' => Lead::where('status', LeadStatus::SALE)->count(),
                ];
            });
        }

        return Inertia::render('LeadPool/Index', [
            'leads' => LeadPoolResource::collection($leads),
            'stats' => $stats,
            'capacityAlerts' => $viewMode === 'pool' ? $this->poolService->checkCapacityAlerts() : [],
            'agents' => $agents->map(fn ($agent) => [
                'id' => $agent->id,
                'name' => $agent->name,
                'active_leads' => $activeLeadCounts[$agent->id] ?? 0,
                'max_active_cycles' => $agent->agentProfile?->max_active_cycles ?? 10,
                'max_daily_leads' => $agent->agentProfile?->max_daily_leads ?? 50,
                'product_skills' => $agent->agentProfile?->product_skills ?? [],
            ]),
            'filters' => array_merge($filters, ['view_mode' => $viewMode]),
            'viewMode' => $viewMode,
            'sourceOptions' => collect(LeadSource::cases())->map(fn ($s) => [
                'value' => $s->value,
                'label' => $s->label(),
            ]),
            'productOptions' => Lead::where('pool_status', PoolStatus::AVAILABLE)
                ->whereNotNull('product_name')
                ->distinct()
                ->orderBy('product_name')
                ->pluck('product_name'),
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
            'product_filter' => ['nullable', 'string', 'max:255'],
        ]);

        // Optionally narrow lead IDs to a specific product before distributing
        $leadIds = $validated['lead_ids'];
        if (! empty($validated['product_filter'])) {
            $leadIds = Lead::whereIn('id', $leadIds)
                ->where('pool_status', PoolStatus::AVAILABLE)
                ->where('product_name', 'ILIKE', '%'.$validated['product_filter'].'%')
                ->pluck('id')
                ->toArray();

            if (empty($leadIds)) {
                return redirect()->back()->with('error', 'No available leads match the selected product filter.');
            }
        }

        if ($validated['method'] === 'equal') {
            $result = $this->distributionService->distributeEqual(
                $leadIds,
                $validated['agent_ids'],
                auth()->id()
            );
        } else {
            $result = $this->distributionService->distributeCustom(
                $leadIds,
                $validated['distribution'],
                auth()->id()
            );
        }

        return redirect()->back()->with('success', "Distributed {$result['total_distributed']} leads to {$result['agent_count']} agents");
    }

    public function capacityAlerts()
    {
        return response()->json([
            'alerts' => $this->poolService->checkCapacityAlerts(),
        ]);
    }

    public function agentPerformance(): Response
    {
        $agents = User::where('role', 'agent')
            ->where('is_active', true)
            ->with('agentProfile')
            ->get();

        $agentIds = $agents->pluck('id')->all();

        // Pre-aggregate active lead counts — single query (ISS-006)
        $activeLeadCounts = Lead::whereIn('assigned_to', $agentIds)
            ->where('pool_status', PoolStatus::ASSIGNED)
            ->selectRaw('assigned_to, count(*) as count')
            ->groupBy('assigned_to')
            ->pluck('count', 'assigned_to');

        // Pre-fetch all today's cycles — single query (ISS-006)
        $todayCyclesAll = LeadCycle::whereIn('assigned_agent_id', $agentIds)
            ->whereDate('opened_at', today())
            ->get()
            ->groupBy('assigned_agent_id');

        $agents = $agents->map(function ($agent) use ($activeLeadCounts, $todayCyclesAll) {
            $todayCycles = $todayCyclesAll->get($agent->id, collect());

            return [
                'id' => $agent->id,
                'name' => $agent->name,
                'active_leads' => $activeLeadCounts[$agent->id] ?? 0,
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
