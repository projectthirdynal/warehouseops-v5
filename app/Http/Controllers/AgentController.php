<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Lead;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AgentController extends Controller
{
    public function index(Request $request)
    {
        $agents = User::where('role', 'agent')
            ->with('agentProfile')
            ->get()
            ->map(function ($agent) {
                // Calculate today's stats for each agent
                $agent->stats = [
                    'leads_today' => Lead::where('assigned_to', $agent->id)
                        ->whereDate('created_at', today())
                        ->count(),
                    'sales_today' => Lead::where('assigned_to', $agent->id)
                        ->where('status', 'SALE')
                        ->whereDate('updated_at', today())
                        ->count(),
                    'conversion_rate' => 0,
                    'active_leads' => Lead::where('assigned_to', $agent->id)
                        ->whereNotIn('status', ['SALE', 'DELIVERED', 'RETURNED', 'CANCELLED', 'ARCHIVED'])
                        ->count(),
                ];

                // Calculate conversion rate
                if ($agent->stats['leads_today'] > 0) {
                    $agent->stats['conversion_rate'] = round(
                        ($agent->stats['sales_today'] / $agent->stats['leads_today']) * 100
                    );
                }

                return $agent;
            });

        $stats = [
            'total' => $agents->count(),
            'active' => $agents->where('is_active', true)->count(),
            'inactive' => $agents->where('is_active', false)->count(),
            'avg_performance' => $agents->avg(fn($a) => $a->agentProfile?->performance_score ?? 50),
        ];

        return Inertia::render('Agents/Index', [
            'agents' => $agents,
            'stats' => $stats,
        ]);
    }

    public function monitoring()
    {
        $metrics = [
            'leads' => [
                'total' => Lead::count(),
                'new_today' => Lead::whereDate('created_at', today())->count(),
                'converted' => Lead::where('status', 'SALE')->count(),
                'conversion_rate' => Lead::count() > 0
                    ? round((Lead::where('status', 'SALE')->count() / Lead::count()) * 100, 1)
                    : 0,
                'trend' => 12,
            ],
            'waybills' => [
                'total' => \App\Models\Waybill::count(),
                'dispatched_today' => \App\Models\Waybill::whereDate('dispatched_at', today())->count(),
                'delivered_today' => \App\Models\Waybill::whereDate('delivered_at', today())->count(),
                'returned_today' => \App\Models\Waybill::whereDate('returned_at', today())->count(),
                'delivery_rate' => 85,
            ],
            'agents' => [
                'total' => User::where('role', 'agent')->count(),
                'online' => User::where('role', 'agent')
                    ->where('is_active', true)
                    ->whereNotNull('last_login_at')
                    ->where('last_login_at', '>=', now()->subHours(1))
                    ->count(),
                'avg_performance' => 72,
                'top_performer' => User::where('role', 'agent')->first()?->name ?? 'N/A',
            ],
            'revenue' => [
                'today' => Lead::where('status', 'SALE')
                    ->whereDate('updated_at', today())
                    ->sum('amount'),
                'this_week' => Lead::where('status', 'SALE')
                    ->whereBetween('updated_at', [now()->startOfWeek(), now()])
                    ->sum('amount'),
                'this_month' => Lead::where('status', 'SALE')
                    ->whereMonth('updated_at', now()->month)
                    ->sum('amount'),
                'trend' => 8,
            ],
        ];

        return Inertia::render('Monitoring/Index', [
            'metrics' => $metrics,
            'hourly_data' => [],
            'agent_performance' => [],
        ]);
    }
}
