<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\User;
use App\Models\Waybill;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        // Waybill statistics
        $totalWaybills = Waybill::count();
        $pendingDispatch = Waybill::where('status', 'PENDING')->count();
        $inTransit = Waybill::whereIn('status', ['DISPATCHED', 'IN_TRANSIT', 'OUT_FOR_DELIVERY'])->count();
        $deliveredToday = Waybill::where('status', 'DELIVERED')
            ->whereDate('delivered_at', today())
            ->count();
        $returnedToday = Waybill::where('status', 'RETURNED')
            ->whereDate('returned_at', today())
            ->count();

        // Lead statistics
        $totalLeads = Lead::count();
        $newLeads = Lead::where('status', 'NEW')
            ->whereNull('assigned_to')
            ->count();
        $salesToday = Lead::where('status', 'SALE')
            ->whereDate('updated_at', today())
            ->count();
        $totalSales = Lead::where('status', 'SALE')->count();
        $conversionRate = $totalLeads > 0
            ? round(($totalSales / $totalLeads) * 100, 1)
            : 0;

        // Operations statistics
        $qcPending = Lead::where('sales_status', 'QA_PENDING')->count();
        $agentsOnline = User::where('role', 'agent')
            ->where('is_active', true)
            ->whereNotNull('last_login_at')
            ->where('last_login_at', '>=', now()->subHour())
            ->count();

        $stats = [
            'total_waybills' => $totalWaybills,
            'pending_dispatch' => $pendingDispatch,
            'in_transit' => $inTransit,
            'delivered_today' => $deliveredToday,
            'returned_today' => $returnedToday,
            'total_leads' => $totalLeads,
            'new_leads' => $newLeads,
            'sales_today' => $salesToday,
            'conversion_rate' => $conversionRate,
            'qc_pending' => $qcPending,
            'agents_online' => $agentsOnline,
        ];

        // Recent activity from various sources
        $recentActivity = collect();

        // Recent waybill deliveries
        $recentDeliveries = Waybill::where('status', 'DELIVERED')
            ->orderBy('delivered_at', 'desc')
            ->limit(3)
            ->get()
            ->map(fn($w) => [
                'id' => $w->id,
                'type' => 'Waybill',
                'message' => "Waybill #{$w->waybill_number} delivered successfully",
                'time' => $w->delivered_at?->diffForHumans() ?? 'recently',
                'timestamp' => $w->delivered_at,
            ]);

        // Recent lead assignments
        $recentAssignments = Lead::whereNotNull('assigned_to')
            ->orderBy('updated_at', 'desc')
            ->limit(3)
            ->get()
            ->map(fn($l) => [
                'id' => $l->id + 10000,
                'type' => 'Lead',
                'message' => "Lead assigned to agent",
                'time' => $l->updated_at->diffForHumans(),
                'timestamp' => $l->updated_at,
            ]);

        // Recent QC approvals
        $recentQC = Lead::where('sales_status', 'QA_APPROVED')
            ->orderBy('updated_at', 'desc')
            ->limit(3)
            ->get()
            ->map(fn($l) => [
                'id' => $l->id + 20000,
                'type' => 'QC',
                'message' => "Sale #{$l->id} approved by QC",
                'time' => $l->updated_at->diffForHumans(),
                'timestamp' => $l->updated_at,
            ]);

        // Merge and sort by timestamp
        $recentActivity = $recentDeliveries
            ->merge($recentAssignments)
            ->merge($recentQC)
            ->sortByDesc('timestamp')
            ->take(10)
            ->values()
            ->map(fn($item) => [
                'id' => $item['id'],
                'type' => $item['type'],
                'message' => $item['message'],
                'time' => $item['time'],
            ])
            ->toArray();

        // If no activity, show placeholder
        if (empty($recentActivity)) {
            $recentActivity = [
                [
                    'id' => 1,
                    'type' => 'System',
                    'message' => 'System initialized - ready for operations',
                    'time' => 'just now',
                ],
            ];
        }

        return Inertia::render('Dashboard/Index', [
            'stats' => $stats,
            'recentActivity' => $recentActivity,
        ]);
    }
}
