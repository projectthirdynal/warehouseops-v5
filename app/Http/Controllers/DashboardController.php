<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DashboardWidgetConfig;
use App\Models\Lead;
use App\Models\Ticket;
use App\Models\Upload;
use App\Models\User;
use App\Models\Waybill;
use App\Models\Invoice;
use App\Domain\Order\Models\Order;
use App\Domain\Product\Models\Product;
use App\Domain\Product\Models\ProductStock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $role = $user?->role ?? 'agent';

        [$stats, $trends, $hourlyActivity, $recentActivity] = $this->buildDashboardData($user);

        return Inertia::render('Dashboard/Index', [
            'stats'          => $stats,
            'recentActivity' => $recentActivity,
            'hourlyActivity' => $hourlyActivity,
            'trends'         => $trends,
            'role'           => $role,
            'widgetConfig'   => $this->getWidgetConfig($user?->id ?? 0, 'main'),
            'alerts'         => $this->buildAlerts(),
            'revenueSummary' => $this->buildRevenueSummary(),
            'operationHeatmap' => $this->buildOperationHeatmap(),
            'agentLeaderboard' => $this->buildAgentLeaderboard(),
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();

        [$stats, $trends, $hourlyActivity, $recentActivity] = $this->buildDashboardData($user);

        return response()->json([
            'stats'          => $stats,
            'recentActivity' => $recentActivity,
            'hourlyActivity' => $hourlyActivity,
            'trends'         => $trends,
            'updated_at'     => now()->toIso8601String(),
        ]);
    }

    public function alerts(Request $request): JsonResponse
    {
        return response()->json([
            'alerts'     => $this->buildAlerts(),
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    public function revenueSummary(Request $request): JsonResponse
    {
        return response()->json([
            'revenue'    => $this->buildRevenueSummary(),
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    private function buildRevenueSummary(): array
    {
        // Revenue from invoices (amount_paid) for today, this week, this month
        $revenueToday = (float) Invoice::whereIn('status', ['PAID', 'PARTIAL'])
            ->whereDate('updated_at', today())
            ->sum('amount_paid');

        $revenueWeek = (float) Invoice::whereIn('status', ['PAID', 'PARTIAL'])
            ->whereBetween('updated_at', [now()->startOfWeek(), now()->endOfWeek()])
            ->sum('amount_paid');

        $revenueMonth = (float) Invoice::whereIn('status', ['PAID', 'PARTIAL'])
            ->whereMonth('updated_at', now()->month)
            ->whereYear('updated_at', now()->year)
            ->sum('amount_paid');

        // Previous period comparisons
        $revenueYesterday = (float) Invoice::whereIn('status', ['PAID', 'PARTIAL'])
            ->whereDate('updated_at', today()->subDay())
            ->sum('amount_paid');

        $revenueLastWeek = (float) Invoice::whereIn('status', ['PAID', 'PARTIAL'])
            ->whereBetween('updated_at', [now()->subWeek()->startOfWeek(), now()->subWeek()->endOfWeek()])
            ->sum('amount_paid');

        $revenueLastMonth = (float) Invoice::whereIn('status', ['PAID', 'PARTIAL'])
            ->whereMonth('updated_at', now()->subMonth()->month)
            ->whereYear('updated_at', now()->subMonth()->year)
            ->sum('amount_paid');

        // Conversion trend (last 7 days)
        $conversionTrend = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = today()->subDays($i);
            $totalLeads = Lead::whereDate('created_at', $date)->count();
            $sales = Lead::where('status', 'SALE')->whereDate('updated_at', $date)->count();
            $conversionTrend[] = [
                'date'       => $date->toDateString(),
                'label'      => $date->format('D'),
                'leads'      => $totalLeads,
                'sales'      => $sales,
                'conversion' => $totalLeads > 0 ? round(($sales / $totalLeads) * 100, 1) : 0,
            ];
        }

        // Top products by revenue (delivered orders, last 30 days)
        $topProductsRaw = Order::select('product_id', DB::raw('SUM(total_amount) as revenue'), DB::raw('COUNT(*) as order_count'))
            ->where('status', 'DELIVERED')
            ->where('created_at', '>=', now()->subDays(30))
            ->whereNotNull('product_id')
            ->groupBy('product_id')
            ->orderByDesc('revenue')
            ->limit(5)
            ->get();

        $productIds = $topProductsRaw->pluck('product_id')->unique()->values()->all();
        $products = Product::whereIn('id', $productIds)->get()->keyBy('id');

        $topProducts = $topProductsRaw->map(function ($row) use ($products) {
            $product = $products->get($row->product_id);
            return [
                'id'          => $row->product_id,
                'name'        => $product?->name ?? "Product #{$row->product_id}",
                'sku'         => $product?->sku ?? '',
                'revenue'     => round((float) $row->revenue, 2),
                'order_count' => (int) $row->order_count,
            ];
        })->values()->all();

        // Calculate trend percentages
        $todayTrend = $revenueYesterday > 0
            ? round((($revenueToday - $revenueYesterday) / $revenueYesterday) * 100, 1)
            : null;
        $weekTrend = $revenueLastWeek > 0
            ? round((($revenueWeek - $revenueLastWeek) / $revenueLastWeek) * 100, 1)
            : null;
        $monthTrend = $revenueLastMonth > 0
            ? round((($revenueMonth - $revenueLastMonth) / $revenueLastMonth) * 100, 1)
            : null;

        return [
            'periods' => [
                'today' => ['value' => round($revenueToday, 2), 'trend' => $todayTrend],
                'week'  => ['value' => round($revenueWeek, 2), 'trend' => $weekTrend],
                'month' => ['value' => round($revenueMonth, 2), 'trend' => $monthTrend],
            ],
            'conversion_trend' => $conversionTrend,
            'top_products'     => $topProducts,
        ];
    }

    public function operationHeatmap(Request $request): JsonResponse
    {
        return response()->json([
            'heatmap'     => $this->buildOperationHeatmap(),
            'updated_at'  => now()->toIso8601String(),
        ]);
    }

    private function buildOperationHeatmap(): array
    {
        // Aggregate waybill + order activity by day-of-week × hour over last 30 days
        // PostgreSQL: EXTRACT(DOW FROM ...) returns 0=Sun..6=Sat, EXTRACT(HOUR FROM ...) returns 0-23

        $waybillHeat = Waybill::selectRaw("
                EXTRACT(DOW FROM created_at)::int AS dow,
                EXTRACT(HOUR FROM created_at)::int AS hour,
                COUNT(*) AS count
            ")
            ->where('created_at', '>=', now()->subDays(30))
            ->groupByRaw('EXTRACT(DOW FROM created_at), EXTRACT(HOUR FROM created_at)')
            ->get()
            ->keyBy(fn ($r) => "{$r->dow}-{$r->hour}");

        $orderHeat = Order::selectRaw("
                EXTRACT(DOW FROM created_at)::int AS dow,
                EXTRACT(HOUR FROM created_at)::int AS hour,
                COUNT(*) AS count
            ")
            ->where('created_at', '>=', now()->subDays(30))
            ->groupByRaw('EXTRACT(DOW FROM created_at), EXTRACT(HOUR FROM created_at)')
            ->get()
            ->keyBy(fn ($r) => "{$r->dow}-{$r->hour}");

        // Build 7×24 grid (dow 0-6, hour 0-23)
        $dayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $grid = [];
        $maxCount = 1;

        for ($dow = 0; $dow < 7; $dow++) {
            $row = ['day' => $dayLabels[$dow], 'cells' => []];
            for ($hour = 0; $hour < 24; $hour++) {
                $key = "{$dow}-{$hour}";
                $wb = (int) ($waybillHeat->get($key)?->count ?? 0);
                $od = (int) ($orderHeat->get($key)?->count ?? 0);
                $total = $wb + $od;
                $maxCount = max($maxCount, $total);
                $row['cells'][] = [
                    'hour'  => $hour,
                    'count' => $total,
                ];
            }
            $grid[] = $row;
        }

        // Peak hours (top 5 by total activity)
        $peakHours = [];
        foreach ($grid as $row) {
            foreach ($row['cells'] as $cell) {
                $peakHours[] = [
                    'day'   => $row['day'],
                    'hour'  => $cell['hour'],
                    'count' => $cell['count'],
                ];
            }
        }
        usort($peakHours, fn ($a, $b) => $b['count'] <=> $a['count']);
        $peakHours = array_slice($peakHours, 0, 5);

        return [
            'grid'       => $grid,
            'max_count'  => $maxCount,
            'peak_hours' => $peakHours,
            'period_days' => 30,
        ];
    }

    public function agentLeaderboard(Request $request): JsonResponse
    {
        return response()->json([
            'leaderboard'  => $this->buildAgentLeaderboard(),
            'updated_at'   => now()->toIso8601String(),
        ]);
    }

    private function buildAgentLeaderboard(): array
    {
        // Top 5 agents by delivered-order revenue today, with rank change vs yesterday

        $todayRows = Order::selectRaw('assigned_agent_id, COUNT(*) as cnt, SUM(total_amount) as rev')
            ->where('status', 'DELIVERED')
            ->whereDate('created_at', today())
            ->whereNotNull('assigned_agent_id')
            ->groupBy('assigned_agent_id')
            ->orderByDesc('rev')
            ->limit(5)
            ->get();

        $yesterdayRows = Order::selectRaw('assigned_agent_id, COUNT(*) as cnt, SUM(total_amount) as rev')
            ->where('status', 'DELIVERED')
            ->whereDate('created_at', today()->subDay())
            ->whereNotNull('assigned_agent_id')
            ->groupBy('assigned_agent_id')
            ->orderByDesc('rev')
            ->get()
            ->keyBy('assigned_agent_id');

        $agentIds = $todayRows->pluck('assigned_agent_id')->unique()->filter()->values()->all();
        $agents = User::whereIn('id', $agentIds)->pluck('name', 'id');

        $totalRevenue = (float) $todayRows->sum('rev');
        $totalOrders = (int) $todayRows->sum('cnt');

        $items = $todayRows->map(function ($r, $i) use ($agents, $yesterdayRows) {
            $yesterdayRank = null;
            $yesterdayRev = 0;
            if ($yesterdayRows->has($r->assigned_agent_id)) {
                $yRev = (float) $yesterdayRows[$r->assigned_agent_id]->rev;
                $yesterdayRev = $yRev;
                $yesterdayRank = 0;
                foreach ($yesterdayRows as $idx => $yRow) {
                    $yesterdayRank++;
                    if ($idx === $r->assigned_agent_id) {
                        break;
                    }
                }
            }

            $todayRank = $i + 1;
            $rankChange = $yesterdayRank !== null ? $yesterdayRank - $todayRank : null;

            return [
                'rank'            => $todayRank,
                'agent_id'        => $r->assigned_agent_id,
                'agent_name'      => $agents[$r->assigned_agent_id] ?? 'Unknown',
                'orders'          => (int) $r->cnt,
                'revenue'         => round((float) $r->rev, 2),
                'avg_order_value' => $r->cnt > 0 ? round((float) $r->rev / (int) $r->cnt, 2) : 0.0,
                'yesterday_rev'   => round($yesterdayRev, 2),
                'rank_change'     => $rankChange,
            ];
        })->values()->all();

        return [
            'items'         => $items,
            'total_revenue' => round($totalRevenue, 2),
            'total_orders'  => $totalOrders,
            'date'          => today()->toDateString(),
        ];
    }

    private function buildAlerts(): array
    {
        $alerts = [];

        // 1. Low stock items
        $lowStock = ProductStock::whereRaw('current_stock - reserved_stock <= reorder_point')
            ->limit(10)
            ->get(['id', 'product_id', 'current_stock', 'reserved_stock', 'reorder_point']);

        foreach ($lowStock as $item) {
            $available = $item->current_stock - $item->reserved_stock;
            $alerts[] = [
                'type'        => 'low_stock',
                'severity'    => $available <= 0 ? 'critical' : 'warning',
                'title'       => "Low Stock: Product #{$item->product_id}",
                'description' => "Available: {$available} units (reorder at {$item->reorder_point})",
                'href'        => '/inventory',
                'created_at'  => now()->toIso8601String(),
            ];
        }

        // 2. SLA breaches — returned waybills older than 7 days
        $beyondSla = Waybill::where('status', 'RETURNED')
            ->where('returned_at', '<', now()->subDays(7))
            ->limit(10)
            ->get(['id', 'waybill_number', 'returned_at']);

        foreach ($beyondSla as $wb) {
            $daysOver = $wb->returned_at?->diffInDays(now()) ?? 0;
            $alerts[] = [
                'type'        => 'sla_breach',
                'severity'    => $daysOver > 14 ? 'critical' : 'warning',
                'title'       => "SLA Breach: {$wb->waybill_number}",
                'description' => "Returned {$daysOver} days ago — beyond 7-day SLA",
                'href'        => '/claims',
                'created_at'  => $wb->returned_at?->toIso8601String() ?? now()->toIso8601String(),
            ];
        }

        // 3. Failed imports (last 7 days)
        $failedImports = Upload::where('type', 'waybill')
            ->whereIn('status', ['failed', 'validation_failed'])
            ->where('created_at', '>=', now()->subDays(7))
            ->limit(5)
            ->get(['id', 'original_filename', 'status', 'created_at']);

        foreach ($failedImports as $upload) {
            $alerts[] = [
                'type'        => 'failed_import',
                'severity'    => 'warning',
                'title'       => "Failed Import: {$upload->original_filename}",
                'description' => "Status: {$upload->status} — retry or re-upload needed",
                'href'        => '/waybills/import',
                'created_at'  => $upload->created_at->toIso8601String(),
            ];
        }

        // 4. Undelivered waybills — dispatched 5+ days ago, still not delivered
        $undelivered = Waybill::whereIn('status', ['DISPATCHED', 'IN_TRANSIT', 'OUT_FOR_DELIVERY'])
            ->where('created_at', '<', now()->subDays(5))
            ->limit(10)
            ->get(['id', 'waybill_number', 'created_at']);

        foreach ($undelivered as $wb) {
            $daysStuck = $wb->created_at->diffInDays(now());
            $alerts[] = [
                'type'        => 'undelivered',
                'severity'    => $daysStuck > 10 ? 'critical' : 'warning',
                'title'       => "Undelivered: {$wb->waybill_number}",
                'description' => "In transit for {$daysStuck} days without delivery",
                'href'        => '/waybills',
                'created_at'  => $wb->created_at->toIso8601String(),
            ];
        }

        // Sort by severity (critical first), then by date descending
        usort($alerts, function ($a, $b) {
            if ($a['severity'] !== $b['severity']) {
                return $a['severity'] === 'critical' ? -1 : 1;
            }
            return strcmp($b['created_at'], $a['created_at']);
        });

        return $alerts;
    }

    private function buildDashboardData(?\App\Models\User $user): array
    {
        // Waybill statistics
        $totalWaybills   = Waybill::count();
        $pendingDispatch = Waybill::where('status', 'PENDING')->count();
        $inTransit       = Waybill::whereIn('status', ['DISPATCHED', 'IN_TRANSIT', 'OUT_FOR_DELIVERY'])->count();

        $deliveredToday     = Waybill::where('status', 'DELIVERED')->whereDate('delivered_at', today())->count();
        $deliveredYesterday = Waybill::where('status', 'DELIVERED')->whereDate('delivered_at', today()->subDay())->count();

        $returnedToday = Waybill::where('status', 'RETURNED')->whereDate('returned_at', today())->count();

        // Lead statistics
        $totalLeads = Lead::count();
        $newLeads   = Lead::where('status', 'NEW')->whereNull('assigned_to')->count();

        $salesToday     = Lead::where('status', 'SALE')->whereDate('updated_at', today())->count();
        $salesYesterday = Lead::where('status', 'SALE')->whereDate('updated_at', today()->subDay())->count();

        $totalSales     = Lead::where('status', 'SALE')->count();
        $conversionRate = $totalLeads > 0 ? round(($totalSales / $totalLeads) * 100, 1) : 0;

        // Operations statistics
        $qcPending    = Lead::where('sales_status', 'QA_PENDING')->count();
        $agentsOnline = User::where('role', 'agent')
            ->where('is_active', true)
            ->whereNotNull('last_login_at')
            ->where('last_login_at', '>=', now()->subHour())
            ->count();

        // Ticket statistics
        $openTickets = Ticket::whereIn('status', ['open', 'in_progress', 'waiting'])->count();
        $myTickets  = Ticket::where('assigned_to', $user?->id)
            ->whereIn('status', ['open', 'in_progress', 'waiting'])
            ->count();

        // Invoice statistics
        $invoicesOverdue = Invoice::where('status', 'OVERDUE')->count();
        $invoicesUnpaid   = Invoice::whereIn('status', ['SENT', 'PARTIAL'])->count();
        $totalRevenue     = (float) Invoice::whereIn('status', ['PAID', 'PARTIAL'])->sum('amount_paid');
        $revenueToday     = (float) Invoice::whereDate('updated_at', today())
            ->whereIn('status', ['PAID', 'PARTIAL'])
            ->sum('amount_paid');

        // Inventory statistics
        $lowStockCount  = ProductStock::whereRaw('current_stock - reserved_stock <= reorder_point')->count();
        $totalProducts  = ProductStock::distinct('product_id')->count('product_id');

        // Claims statistics
        $claimsPending  = Waybill::where('status', 'RETURNED')->whereNull('returned_at')->orWhereNull('delivered_at')->where('status', 'CLAIMED')->count();
        $beyondSlaCount = Waybill::where('status', 'RETURNED')->where('returned_at', '<', now()->subDays(7))->count();

        $stats = [
            'total_waybills'  => $totalWaybills,
            'pending_dispatch' => $pendingDispatch,
            'in_transit'      => $inTransit,
            'delivered_today' => $deliveredToday,
            'returned_today'  => $returnedToday,
            'total_leads'     => $totalLeads,
            'new_leads'       => $newLeads,
            'sales_today'     => $salesToday,
            'conversion_rate' => $conversionRate,
            'qc_pending'      => $qcPending,
            'agents_online'   => $agentsOnline,
            'open_tickets'    => $openTickets,
            'my_tickets'      => $myTickets,
            'invoices_overdue' => $invoicesOverdue,
            'invoices_unpaid'  => $invoicesUnpaid,
            'total_revenue'    => round($totalRevenue, 2),
            'revenue_today'    => round($revenueToday, 2),
            'low_stock_count'  => $lowStockCount,
            'total_products'   => $totalProducts,
            'claims_pending'   => $claimsPending,
            'beyond_sla_count'  => $beyondSlaCount,
        ];

        // Trend vs yesterday
        $trends = [
            'delivered' => $deliveredYesterday > 0
                ? (int) round((($deliveredToday - $deliveredYesterday) / $deliveredYesterday) * 100)
                : null,
            'sales' => $salesYesterday > 0
                ? (int) round((($salesToday - $salesYesterday) / $salesYesterday) * 100)
                : null,
        ];

        // Hourly waybill count for today
        $rawHourly = Waybill::selectRaw('EXTRACT(HOUR FROM created_at)::int AS hour, COUNT(*) AS cnt')
            ->whereDate('created_at', today())
            ->whereRaw('EXTRACT(HOUR FROM created_at) BETWEEN 8 AND 19')
            ->groupByRaw('EXTRACT(HOUR FROM created_at)::int')
            ->pluck('cnt', 'hour');

        $hourlyActivity = [];
        for ($h = 8; $h <= 19; $h++) {
            $hourlyActivity[] = ['hour' => (string) $h, 'waybills' => (int) ($rawHourly[$h] ?? 0)];
        }

        // Recent activity
        $recentDeliveries = Waybill::where('status', 'DELIVERED')
            ->orderBy('delivered_at', 'desc')
            ->limit(3)
            ->get()
            ->map(fn ($w) => [
                'id'        => 'waybill-' . $w->id,
                'type'      => 'Waybill',
                'message'   => "Waybill #{$w->waybill_number} delivered successfully",
                'time'      => $w->delivered_at?->diffForHumans() ?? 'recently',
                '_ts'       => $w->delivered_at,
            ])
            ->toArray();

        $recentAssignments = Lead::whereNotNull('assigned_to')
            ->orderBy('updated_at', 'desc')
            ->limit(3)
            ->get()
            ->map(fn ($l) => [
                'id'      => 'lead-' . $l->id,
                'type'    => 'Lead',
                'message' => 'Lead assigned to agent',
                'time'    => $l->updated_at->diffForHumans(),
                '_ts'     => $l->updated_at,
            ])
            ->toArray();

        $recentQC = Lead::where('sales_status', 'QA_APPROVED')
            ->orderBy('updated_at', 'desc')
            ->limit(3)
            ->get()
            ->map(fn ($l) => [
                'id'      => 'qc-' . $l->id,
                'type'    => 'QC',
                'message' => "Sale #{$l->id} approved by QC",
                'time'    => $l->updated_at->diffForHumans(),
                '_ts'     => $l->updated_at,
            ])
            ->toArray();

        $recentActivity = array_merge($recentDeliveries, $recentAssignments, $recentQC);
        usort($recentActivity, fn ($a, $b) => ($b['_ts'] ?? null) <=> ($a['_ts'] ?? null));
        $recentActivity = array_slice($recentActivity, 0, 10);
        $recentActivity = array_map(fn ($item) => [
            'id'      => $item['id'],
            'type'    => $item['type'],
            'message' => $item['message'],
            'time'    => $item['time'],
        ], $recentActivity);

        return [$stats, $trends, $hourlyActivity, $recentActivity];
    }

    // ── Widget configuration ──

    public static function availableWidgets(): array
    {
        return [
            ['key' => 'stat_cards_1', 'label' => 'Stat Cards (Row 1)', 'description' => 'Primary role-based statistics', 'category' => 'stats', 'default_visible' => true, 'default_order' => 1],
            ['key' => 'stat_cards_2', 'label' => 'Stat Cards (Row 2)', 'description' => 'Secondary role-based statistics', 'category' => 'stats', 'default_visible' => true, 'default_order' => 2],
            ['key' => 'hourly_chart', 'label' => 'Hourly Activity Chart', 'description' => 'Waybill activity by hour', 'category' => 'charts', 'default_visible' => true, 'default_order' => 3],
            ['key' => 'recent_activity', 'label' => 'Recent Activity', 'description' => 'Latest system events feed', 'category' => 'activity', 'default_visible' => true, 'default_order' => 4],
            ['key' => 'summary_stats', 'label' => 'Summary Statistics', 'description' => 'Role-based summary metrics', 'category' => 'stats', 'default_visible' => true, 'default_order' => 5],
            ['key' => 'quick_actions', 'label' => 'Quick Actions', 'description' => 'Role-based shortcut buttons', 'category' => 'actions', 'default_visible' => true, 'default_order' => 6],
            ['key' => 'alerts_widget', 'label' => 'Alerts', 'description' => 'Low stock, SLA breaches, failed imports, undelivered waybills', 'category' => 'alerts', 'default_visible' => true, 'default_order' => 7],
            ['key' => 'revenue_summary', 'label' => 'Revenue Summary', 'description' => 'Today/week/month revenue, top products, conversion trend', 'category' => 'revenue', 'default_visible' => true, 'default_order' => 8],
            ['key' => 'ops_heatmap', 'label' => 'Operations Heatmap', 'description' => 'Hourly activity across days of week (30-day window)', 'category' => 'charts', 'default_visible' => true, 'default_order' => 9],
            ['key' => 'agent_leaderboard', 'label' => 'Agent Leaderboard', 'description' => 'Top 5 agents by sales today with rank change', 'category' => 'performance', 'default_visible' => true, 'default_order' => 10],
        ];
    }

    public function widgetConfig(Request $request): JsonResponse
    {
        $userId = $request->user()?->id ?? 0;
        $dashboard = (string) $request->query('dashboard', 'main');

        return response()->json($this->getWidgetConfig($userId, $dashboard));
    }

    public function saveWidgetConfig(Request $request): JsonResponse
    {
        $userId = $request->user()?->id ?? 0;
        $dashboard = (string) $request->input('dashboard', 'main');
        $widgets = $request->input('widgets', []);
        $availableKeys = array_column(self::availableWidgets(), 'key');

        foreach ($widgets as $config) {
            $key = $config['key'] ?? null;
            if (!$key || !in_array($key, $availableKeys)) {
                continue;
            }
            DashboardWidgetConfig::updateOrCreate(
                [
                    'user_id' => $userId,
                    'dashboard' => $dashboard,
                    'widget_key' => $key,
                ],
                [
                    'is_visible' => $config['is_visible'] ?? true,
                    'sort_order' => $config['sort_order'] ?? 0,
                    'settings' => $config['settings'] ?? null,
                ]
            );
        }

        return response()->json($this->getWidgetConfig($userId, $dashboard));
    }

    public function resetWidgetConfig(Request $request): JsonResponse
    {
        $userId = $request->user()?->id ?? 0;
        $dashboard = (string) $request->query('dashboard', 'main');

        DashboardWidgetConfig::where('user_id', $userId)
            ->where('dashboard', $dashboard)
            ->delete();

        return response()->json($this->getWidgetConfig($userId, $dashboard));
    }

    private function getWidgetConfig(int $userId, string $dashboard): array
    {
        $configs = DashboardWidgetConfig::where('user_id', $userId)
            ->where('dashboard', $dashboard)
            ->get()
            ->keyBy('widget_key');

        $available = self::availableWidgets();
        $widgets = [];

        foreach ($available as $widget) {
            $config = $configs->get($widget['key']);
            $widgets[] = [
                'key' => $widget['key'],
                'label' => $widget['label'],
                'description' => $widget['description'],
                'category' => $widget['category'],
                'is_visible' => $config?->is_visible ?? $widget['default_visible'],
                'sort_order' => $config?->sort_order ?? $widget['default_order'],
                'settings' => $config?->settings ?? [],
            ];
        }

        usort($widgets, fn ($a, $b) => $a['sort_order'] <=> $b['sort_order']);

        $visible = array_values(array_filter($widgets, fn ($w) => $w['is_visible']));
        $hidden = array_values(array_filter($widgets, fn ($w) => !$w['is_visible']));

        return [
            'widgets' => $widgets,
            'visible_widgets' => $visible,
            'hidden_widgets' => $hidden,
            'dashboard' => $dashboard,
        ];
    }
}
