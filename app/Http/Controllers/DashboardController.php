<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\User;
use App\Models\Waybill;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
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
        ];

        // Trend vs yesterday (null when yesterday had no data to avoid misleading +∞%)
        $trends = [
            'delivered' => $deliveredYesterday > 0
                ? (int) round((($deliveredToday - $deliveredYesterday) / $deliveredYesterday) * 100)
                : null,
            'sales' => $salesYesterday > 0
                ? (int) round((($salesToday - $salesYesterday) / $salesYesterday) * 100)
                : null,
        ];

        // Hourly waybill count for today — single aggregated query (8 AM – 7 PM)
        $rawHourly = Waybill::selectRaw('EXTRACT(HOUR FROM created_at)::int AS hour, COUNT(*) AS cnt')
            ->whereDate('created_at', today())
            ->whereRaw('EXTRACT(HOUR FROM created_at) BETWEEN 8 AND 19')
            ->groupByRaw('EXTRACT(HOUR FROM created_at)::int')
            ->pluck('cnt', 'hour');

        $hourlyActivity = [];
        for ($h = 8; $h <= 19; $h++) {
            $hourlyActivity[] = ['hour' => (string) $h, 'waybills' => (int) ($rawHourly[$h] ?? 0)];
        }

        // Recent activity merged from deliveries, lead assignments, and QC approvals
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
            ]);

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
            ]);

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
            ]);

        $recentActivity = $recentDeliveries
            ->merge($recentAssignments)
            ->merge($recentQC)
            ->sortByDesc('_ts')
            ->take(10)
            ->values()
            ->map(fn ($item) => [
                'id'      => $item['id'],
                'type'    => $item['type'],
                'message' => $item['message'],
                'time'    => $item['time'],
            ])
            ->toArray();

        return Inertia::render('Dashboard/Index', [
            'stats'          => $stats,
            'recentActivity' => $recentActivity,
            'hourlyActivity' => $hourlyActivity,
            'trends'         => $trends,
        ]);
    }
}
