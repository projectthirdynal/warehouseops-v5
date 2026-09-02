<?php

namespace App\Http\Controllers;

use Modules\Finance\Models\AgentCommission;
use Modules\Finance\Models\CodSettlement;
use Modules\Finance\Models\CommissionRule;
use Modules\Finance\Models\CommissionRun;
use Modules\Finance\Services\CodReconciliationService;
use Modules\Finance\Services\CommissionService;
use Modules\Finance\Services\FinanceDashboardService;
use Modules\Finance\Services\RevenueService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class FinanceController extends Controller
{
    public function __construct(
        private CommissionService $commissions,
        private RevenueService $revenue,
        private CodReconciliationService $codReconciliation,
        private FinanceDashboardService $dashboardService,
    ) {}

    public function dashboard(Request $request)
    {
        $from = $request->filled('from') ? Carbon::parse($request->from) : now()->startOfMonth();
        $to = $request->filled('to') ? Carbon::parse($request->to)->endOfDay() : now()->endOfDay();

        $summary = $this->revenue->getSummary($from, $to);
        $dailyRevenue = $this->revenue->getDailyRevenue(30);

        $commissionStats = [
            'pending_total' => (float) AgentCommission::where('status', 'PENDING')->sum('commission_amount'),
            'pending_count' => AgentCommission::where('status', 'PENDING')->count(),
            'approved_total' => (float) AgentCommission::where('status', 'APPROVED')->sum('commission_amount'),
            'paid_this_month' => (float) AgentCommission::where('status', 'PAID')
                ->whereMonth('paid_at', now()->month)->sum('commission_amount'),
        ];

        $codStats = [
            'pending' => (float) CodSettlement::where('status', 'PENDING')->sum('net_amount'),
            'received_this_month' => (float) CodSettlement::where('status', 'RECEIVED')
                ->whereMonth('received_at', now()->month)->sum('net_amount'),
        ];

        return Inertia::render('Finance/Dashboard', [
            'summary' => $summary,
            'dailyRevenue' => $dailyRevenue,
            'commissionStats' => $commissionStats,
            'codStats' => $codStats,
            'enhanced' => $this->dashboardService->getDashboardData($from, $to),
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]);
    }

    public function apiDashboard(Request $request)
    {
        $from = $request->filled('from') ? Carbon::parse($request->from) : now()->startOfMonth();
        $to = $request->filled('to') ? Carbon::parse($request->to)->endOfDay() : now()->endOfDay();

        return response()->json($this->dashboardService->getDashboardData($from, $to));
    }

    public function apiCashFlow(Request $request)
    {
        $from = $request->filled('from') ? Carbon::parse($request->from) : now()->startOfMonth();
        $to = $request->filled('to') ? Carbon::parse($request->to)->endOfDay() : now()->endOfDay();

        return response()->json($this->dashboardService->getCashFlow($from, $to));
    }

    public function apiPlTrend(Request $request)
    {
        $months = (int) ($request->get('months', 6));

        return response()->json($this->dashboardService->getPlTrend($months));
    }

    public function apiBalanceSheet()
    {
        return response()->json($this->dashboardService->getBalanceSheet());
    }

    public function apiRevenueTrends(Request $request)
    {
        $from = $request->filled('from') ? Carbon::parse($request->from) : now()->startOfMonth();
        $to = $request->filled('to') ? Carbon::parse($request->to)->endOfDay() : now()->endOfDay();

        return response()->json($this->dashboardService->getRevenueTrends($from, $to));
    }

    public function commissions(Request $request)
    {
        $query = AgentCommission::with(['agent', 'order', 'product']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('agent_id')) {
            $query->where('agent_id', $request->agent_id);
        }

        $commissions = $query->orderBy('created_at', 'desc')->paginate(20)->withQueryString();

        $stats = [
            'pending' => (float) AgentCommission::where('status', 'PENDING')->sum('commission_amount'),
            'approved' => (float) AgentCommission::where('status', 'APPROVED')->sum('commission_amount'),
            'paid' => (float) AgentCommission::where('status', 'PAID')->sum('commission_amount'),
        ];

        $rules = CommissionRule::with('product')->where('is_active', true)->get();

        return Inertia::render('Finance/Commissions', [
            'commissions' => $commissions,
            'stats' => $stats,
            'rules' => $rules,
            'filters' => $request->only(['status', 'agent_id']),
        ]);
    }

    public function approveCommissions(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'exists:agent_commissions,id'],
        ]);

        $count = $this->commissions->approveCommissions($validated['ids']);

        return back()->with('success', "{$count} commission(s) approved.");
    }

    public function payCommissions(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'exists:agent_commissions,id'],
        ]);

        $count = $this->commissions->markAsPaid($validated['ids']);

        return back()->with('success', "{$count} commission(s) marked as paid.");
    }

    public function storeRule(Request $request)
    {
        $validated = $request->validate([
            'product_id' => ['nullable', 'exists:products,id'],
            'rate_type' => ['required', 'in:PERCENTAGE,FIXED'],
            'rate_value' => ['required', 'numeric', 'min:0'],
            'min_sale_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        CommissionRule::create(array_merge($validated, ['is_active' => true]));

        return back()->with('success', 'Commission rule created.');
    }

    public function codSettlements(Request $request)
    {
        $settlements = CodSettlement::orderBy('created_at', 'desc')
            ->paginate(20)
            ->withQueryString();

        $stats = [
            'pending_amount' => (float) CodSettlement::where('status', 'PENDING')->sum('net_amount'),
            'received_amount' => (float) CodSettlement::where('status', 'RECEIVED')->sum('net_amount'),
            'total_collected' => (float) CodSettlement::sum('total_cod_collected'),
        ];

        return Inertia::render('Finance/CodSettlements', [
            'settlements' => $settlements,
            'stats' => $stats,
        ]);
    }

    public function storeCodSettlement(Request $request)
    {
        $validated = $request->validate([
            'courier_code' => ['required', 'string'],
            'reference_number' => ['nullable', 'string'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'total_cod_collected' => ['required', 'numeric', 'min:0'],
            'courier_fee' => ['required', 'numeric', 'min:0'],
            'order_count' => ['required', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $validated['net_amount'] = $validated['total_cod_collected'] - $validated['courier_fee'];
        $validated['status'] = 'PENDING';

        CodSettlement::create($validated);

        return back()->with('success', 'COD settlement recorded.');
    }

    public function receiveCodSettlement(CodSettlement $settlement)
    {
        $settlement->update([
            'status' => 'RECEIVED',
            'received_at' => now(),
        ]);

        return back()->with('success', 'Settlement marked as received.');
    }

    // ── Commission Run Automation ──────────────────────────────────────────

    public function commissionAutomation(Request $request)
    {
        $query = CommissionRun::with(['creator:id,name', 'approver:id,name', 'payer:id,name']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $runs = $query->orderBy('created_at', 'desc')->paginate(15)->withQueryString();
        $stats = $this->commissions->getRunStats();
        $settings = $this->commissions->getSettings();

        return Inertia::render('Finance/CommissionAutomation', [
            'runs' => $runs,
            'stats' => $stats,
            'settings' => $settings,
            'filters' => $request->only(['status']),
        ]);
    }

    public function commissionRunShow(CommissionRun $run)
    {
        $run->load(['creator:id,name', 'approver:id,name', 'payer:id,name']);
        $commissions = $run->commissions()
            ->with(['agent:id,name', 'order:id,order_number', 'product:id,name'])
            ->orderBy('commission_amount', 'desc')
            ->paginate(25);
        $agentBreakdown = $this->commissions->getRunAgentBreakdown($run);

        return Inertia::render('Finance/CommissionRunDetail', [
            'run' => $run,
            'commissions' => $commissions,
            'agentBreakdown' => $agentBreakdown,
        ]);
    }

    public function createCommissionRun(Request $request)
    {
        $validated = $request->validate([
            'period_type' => ['required', 'in:DAILY,WEEKLY,MONTHLY,MANUAL'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
        ]);

        $run = $this->commissions->createRun(
            $validated['period_type'],
            isset($validated['period_start']) ? Carbon::parse($validated['period_start']) : null,
            isset($validated['period_end']) ? Carbon::parse($validated['period_end'])->endOfDay() : null,
            $request->user()->id,
        );

        return back()->with('success', "Commission run '{$run->name}' created with {$run->commission_count} commissions.");
    }

    public function approveCommissionRun(Request $request, CommissionRun $run)
    {
        $run = $this->commissions->approveRun($run, $request->user()->id);

        return back()->with('success', "Run '{$run->name}' approved. {$run->commission_count} commissions ready for payout.");
    }

    public function rejectCommissionRun(Request $request, CommissionRun $run)
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $run = $this->commissions->rejectRun($run, $request->user()->id, $validated['reason']);

        return back()->with('success', "Run '{$run->name}' rejected.");
    }

    public function payCommissionRun(Request $request, CommissionRun $run)
    {
        $run = $this->commissions->payRun($run, $request->user()->id);

        return back()->with('success', "Run '{$run->name}' paid out. {$run->commission_count} commissions marked as paid.");
    }

    public function rejectCommission(Request $request)
    {
        $validated = $request->validate([
            'commission_id' => ['required', 'integer', 'exists:agent_commissions,id'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $this->commissions->rejectCommission($validated['commission_id'], $validated['reason']);

        return back()->with('success', 'Commission rejected.');
    }

    public function updateCommissionSettings(Request $request)
    {
        $validated = $request->validate([
            'frequency' => ['nullable', 'in:DAILY,WEEKLY,MONTHLY'],
            'auto_generate_enabled' => ['nullable', 'boolean'],
            'auto_approve_threshold' => ['nullable', 'numeric', 'min:0'],
            'min_commission_amount' => ['nullable', 'numeric', 'min:0'],
            'require_approval' => ['nullable', 'boolean'],
        ]);

        $this->commissions->updateSettings($validated);

        return back()->with('success', 'Commission automation settings updated.');
    }

    // ── COD Reconciliation ─────────────────────────────────────────────────

    public function codReconciliation(Request $request)
    {
        $query = CodSettlement::with(['reconciledBy:id,name'])
            ->whereIn('status', ['RECEIVED', 'RECONCILED']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('courier_code')) {
            $query->where('courier_code', $request->courier_code);
        }

        $settlements = $query->orderBy('created_at', 'desc')->paginate(15)->withQueryString();
        $stats = $this->codReconciliation->getStats();

        return Inertia::render('Finance/CodReconciliation', [
            'settlements' => $settlements,
            'stats' => $stats,
            'filters' => $request->only(['status', 'courier_code']),
        ]);
    }

    public function codReconciliationShow(CodSettlement $settlement)
    {
        $settlement->load(['reconciledBy:id,name']);
        $items = $settlement->reconciliationItems()
            ->with(['order:id,order_number,cod_amount,receiver_name', 'waybill:id,waybill_number,amount'])
            ->orderBy('match_status')
            ->orderBy('expected_cod', 'desc')
            ->paginate(50);

        $unmatchedOrders = $this->codReconciliation->getUnmatchedOrders($settlement);

        return Inertia::render('Finance/CodReconciliationDetail', [
            'settlement' => $settlement,
            'items' => $items,
            'unmatchedOrders' => $unmatchedOrders->map(fn ($o) => [
                'id' => $o->id,
                'order_number' => $o->order_number,
                'cod_amount' => (float) $o->cod_amount,
                'receiver_name' => $o->receiver_name,
                'delivered_at' => $o->delivered_at?->toDateTimeString(),
            ]),
        ]);
    }

    public function autoMatchCodSettlement(CodSettlement $settlement)
    {
        $result = $this->codReconciliation->autoMatch($settlement);

        return back()->with('success', "Auto-match complete: {$result['matched']} matched, {$result['unmatched']} unmatched. Expected COD: {$result['expected_cod']}, Variance: {$result['variance']}");
    }

    public function manualMatchCodItem(Request $request)
    {
        $validated = $request->validate([
            'item_id' => ['required', 'integer', 'exists:cod_reconciliation_items,id'],
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'remitted_cod' => ['nullable', 'numeric', 'min:0'],
        ]);

        $this->codReconciliation->manualMatch(
            $validated['item_id'],
            $validated['order_id'],
            $validated['remitted_cod'] ?? null,
        );

        return back()->with('success', 'Item manually matched.');
    }

    public function unmatchCodItem(Request $request)
    {
        $validated = $request->validate([
            'item_id' => ['required', 'integer', 'exists:cod_reconciliation_items,id'],
        ]);

        $this->codReconciliation->unmatch($validated['item_id']);

        return back()->with('success', 'Item unmatched.');
    }

    public function finalizeCodReconciliation(Request $request, CodSettlement $settlement)
    {
        $settlement = $this->codReconciliation->finalize($settlement, $request->user()->id);

        return back()->with('success', "Settlement #{$settlement->id} reconciled successfully.");
    }
}
