<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Modules\Leads\Enums\SalesStatus;
use App\Models\Lead;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SalesTrackingController extends Controller
{
    // Forward-pipeline stages shown in the funnel (excludes QA_REJECTED / CANCELLED)
    private const FUNNEL_STAGES = [
        'NEW',
        'CONTACTED',
        'AGENT_CONFIRMED',
        'QA_PENDING',
        'QA_APPROVED',
        'OPS_APPROVED',
        'WAYBILL_CREATED',
    ];

    public function index(Request $request): Response
    {
        [$from, $to] = $this->dateRange($request);

        $base = Lead::where('status', 'SALE')->whereBetween('leads.updated_at', [$from, $to]);

        // ── Stats ────────────────────────────────────────────────────────────
        $totalSales = (clone $base)->count();

        $totalLeadsInPeriod = Lead::whereBetween('created_at', [$from, $to])->count();
        $conversionRate = $totalLeadsInPeriod > 0
            ? round(($totalSales / $totalLeadsInPeriod) * 100, 1)
            : 0.0;

        $days = max((int) $from->diffInDays($to), 1);
        $avgPerDay = $days > 0 ? round($totalSales / $days, 1) : 0.0;

        $topAgentRow = (clone $base)
            ->join('users', 'leads.assigned_to', '=', 'users.id')
            ->selectRaw('users.name, COUNT(leads.id) as cnt')
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('cnt')
            ->first();

        $stats = [
            'total_sales' => $totalSales,
            'conversion_rate' => $conversionRate,
            'avg_per_day' => $avgPerDay,
            'top_agent' => $topAgentRow?->name ?? 'N/A',
        ];

        // ── Daily trend (line chart) ─────────────────────────────────────────
        $dailyTrend = (clone $base)
            ->selectRaw('DATE(updated_at) AS date, COUNT(*) AS count')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($r) => [
                'date' => Carbon::parse($r->date)->format('M d'),
                'count' => (int) $r->count,
            ])
            ->toArray();

        // ── Sales by agent — top 10 (bar chart) ─────────────────────────────
        $agentSales = (clone $base)
            ->join('users', 'leads.assigned_to', '=', 'users.id')
            ->selectRaw('users.name AS agent_name, COUNT(leads.id) AS count')
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->map(fn ($r) => ['agent_name' => $r->agent_name, 'count' => (int) $r->count])
            ->toArray();

        // ── Sales funnel (forward stages only) ──────────────────────────────
        $stageCounts = (clone $base)
            ->selectRaw('sales_status, COUNT(*) AS count')
            ->groupBy('sales_status')
            ->pluck('count', 'sales_status');

        $funnelData = array_map(fn ($s) => [
            'stage' => SalesStatus::from($s)->label(),
            'count' => (int) ($stageCounts[$s] ?? 0),
        ], self::FUNNEL_STAGES);

        // ── Paginated sales table ────────────────────────────────────────────
        $salesLeads = $this->salesLeadsQuery($request, $from, $to);

        // ── Agent list for filter dropdown ───────────────────────────────────
        $agents = User::where('role', 'agent')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->toArray();

        return Inertia::render('Sales/Index', [
            'stats' => $stats,
            'dailyTrend' => $dailyTrend,
            'agentSales' => $agentSales,
            'funnelData' => $funnelData,
            'salesLeads' => $salesLeads,
            'agents' => $agents,
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'search' => $request->input('search', ''),
                'agent' => $request->input('agent', ''),
                'sort' => $request->input('sort', 'updated_at'),
                'dir' => $request->input('dir', 'desc'),
            ],
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function dateRange(Request $request): array
    {
        $from = Carbon::parse($request->input('from', now()->startOfMonth()->toDateString()))->startOfDay();
        $to = Carbon::parse($request->input('to', now()->toDateString()))->endOfDay();

        // Guard: clamp range to 366 days max
        if ($from->diffInDays($to) > 366) {
            $from = $to->copy()->subYear();
        }

        return [$from, $to];
    }

    private function salesLeadsQuery(Request $request, Carbon $from, Carbon $to)
    {
        $allowedSorts = ['name', 'updated_at', 'amount', 'sales_status'];
        $sort = in_array($request->input('sort'), $allowedSorts, true)
            ? $request->input('sort')
            : 'updated_at';
        $dir = $request->input('dir') === 'asc' ? 'asc' : 'desc';

        $query = Lead::with('assignedAgent:id,name')
            ->where('status', 'SALE')
            ->whereBetween('leads.updated_at', [$from, $to]);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(fn ($q) => $q
                ->where('name', 'ILIKE', "%{$search}%")
                ->orWhere('phone', 'ILIKE', "%{$search}%")
                ->orWhere('product_name', 'ILIKE', "%{$search}%")
            );
        }

        if ($request->filled('agent')) {
            $query->where('assigned_to', $request->input('agent'));
        }

        return $query
            ->orderBy($sort, $dir)
            ->paginate(20)
            ->withQueryString()
            ->through(fn ($lead) => [
                'id' => $lead->id,
                'name' => $lead->name,
                'phone' => $lead->phone,
                'product_name' => $lead->product_name,
                'amount' => $lead->amount,
                'sales_status' => $lead->sales_status,
                'agent_name' => $lead->assignedAgent?->name,
                'updated_at' => $lead->updated_at->toDateTimeString(),
            ]);
    }
}
