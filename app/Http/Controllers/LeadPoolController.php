<?php

namespace App\Http\Controllers;

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
            if (!in_array(auth()->user()->role, ['supervisor', 'admin'])) {
                abort(403, 'Supervisors only');
            }
            return $next($request);
        });
    }

    public function index(Request $request): Response
    {
        $filters = $request->only(['source', 'city', 'product_name', 'pool_status']);

        $query = Lead::with(['assignedAgent', 'customer']);

        if (isset($filters['pool_status']) && $filters['pool_status'] !== 'all') {
            $query->where('pool_status', $filters['pool_status']);
        } else {
            $query->where('pool_status', PoolStatus::AVAILABLE);
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

        return Inertia::render('LeadPool/Index', [
            'leads' => LeadPoolResource::collection($leads),
            'stats' => $this->poolService->getPoolStats(),
            'agents' => $agents->map(fn($agent) => [
                'id' => $agent->id,
                'name' => $agent->name,
                'active_leads' => Lead::where('assigned_to', $agent->id)
                    ->where('pool_status', PoolStatus::ASSIGNED)->count(),
                'max_active_cycles' => $agent->agentProfile->max_active_cycles ?? 10,
            ]),
            'filters' => $filters,
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
