<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Modules\Finance\Models\CogsEntry;
use Modules\Finance\Services\CogsDashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class CostOfGoodsController extends Controller
{
    public function __construct(
        private readonly CogsDashboardService $dashboardService,
    ) {}

    public function index(Request $request)
    {
        $entries = CogsEntry::with(['product:id,sku,name', 'costLot:id,batch_number,expiry_date,received_at'])
            ->when($request->from, fn ($q, $v) => $q->where('recorded_at', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->where('recorded_at', '<=', $v.' 23:59:59'))
            ->when($request->product_id, fn ($q, $v) => $q->where('product_id', $v))
            ->latest('recorded_at')
            ->paginate(50)
            ->withQueryString();

        $totals = [
            'total_cost' => (float) CogsEntry::when($request->from, fn ($q, $v) => $q->where('recorded_at', '>=', $v))
                ->when($request->to, fn ($q, $v) => $q->where('recorded_at', '<=', $v.' 23:59:59'))
                ->sum('total_cost'),
            'total_quantity' => (float) CogsEntry::when($request->from, fn ($q, $v) => $q->where('recorded_at', '>=', $v))
                ->when($request->to, fn ($q, $v) => $q->where('recorded_at', '<=', $v.' 23:59:59'))
                ->sum('quantity'),
            'entries_count' => (int) CogsEntry::when($request->from, fn ($q, $v) => $q->where('recorded_at', '>=', $v))
                ->when($request->to, fn ($q, $v) => $q->where('recorded_at', '<=', $v.' 23:59:59'))
                ->count(),
        ];

        $byProduct = DB::table('cogs_entries')
            ->join('products', 'products.id', '=', 'cogs_entries.product_id')
            ->select('products.id', 'products.sku', 'products.name',
                DB::raw('SUM(cogs_entries.quantity) as total_qty'),
                DB::raw('SUM(cogs_entries.total_cost) as total_cost'))
            ->when($request->from, fn ($q, $v) => $q->where('cogs_entries.recorded_at', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->where('cogs_entries.recorded_at', '<=', $v.' 23:59:59'))
            ->groupBy('products.id', 'products.sku', 'products.name')
            ->orderByDesc('total_cost')
            ->limit(10)
            ->get();

        return Inertia::render('Finance/CostOfGoods/Index', [
            'entries' => $entries,
            'totals' => $totals,
            'by_product' => $byProduct,
            'filters' => $request->only(['from', 'to', 'product_id']),
        ]);
    }

    public function dashboard(Request $request)
    {
        $filters = $request->only(['days', 'severity', 'resolved']);

        return Inertia::render('Finance/CostOfGoods/Dashboard', [
            'dashboard' => $this->dashboardService->getDashboardData($filters),
            'filters' => $filters,
        ]);
    }

    public function apiDashboard(Request $request)
    {
        return response()->json(
            $this->dashboardService->getDashboardData($request->only(['days', 'severity', 'resolved'])),
        );
    }

    public function apiDailySummary(Request $request)
    {
        $validated = $request->validate(['date' => 'required|date']);

        return response()->json(
            $this->dashboardService->getDailySummaryDetail($validated['date']),
        );
    }

    public function resolveAlert(Request $request, int $alertId)
    {
        $validated = $request->validate(['note' => 'nullable|string|max:500']);

        $alert = $this->dashboardService->resolveAlert(
            $alertId,
            $request->user()->id,
            $validated['note'] ?? null,
        );

        return back()->with('success', "Alert resolved: {$alert->message}");
    }
}
