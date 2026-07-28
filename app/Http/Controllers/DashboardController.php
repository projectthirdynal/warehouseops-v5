<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DashboardWidgetConfig;
use App\Models\Lead;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Waybill;
use App\Models\Invoice;
use App\Domain\Product\Models\ProductStock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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
