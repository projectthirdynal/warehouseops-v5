<?php

namespace App\Http\Controllers;

use App\Domain\Finance\Models\AgentCommission;
use App\Domain\Finance\Models\CodSettlement;
use App\Domain\Finance\Models\CommissionRule;
use App\Domain\Finance\Services\CommissionService;
use App\Domain\Finance\Services\RevenueService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class FinanceController extends Controller
{
    public function __construct(
        private CommissionService $commissions,
        private RevenueService $revenue,
    ) {}

    public function dashboard(Request $request)
    {
        $from = $request->filled('from') ? Carbon::parse($request->from) : now()->startOfMonth();
        $to = $request->filled('to') ? Carbon::parse($request->to)->endOfDay() : now()->endOfDay();

        $summary = $this->revenue->getSummary($from, $to);
        $dailyRevenue = $this->revenue->getDailyRevenue(30);

        $commissionStats = [
            'pending_total'  => (float) AgentCommission::where('status', 'PENDING')->sum('commission_amount'),
            'pending_count'  => AgentCommission::where('status', 'PENDING')->count(),
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
            'summary'         => $summary,
            'dailyRevenue'    => $dailyRevenue,
            'commissionStats' => $commissionStats,
            'codStats'        => $codStats,
            'filters'         => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]);
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
            'pending'  => (float) AgentCommission::where('status', 'PENDING')->sum('commission_amount'),
            'approved' => (float) AgentCommission::where('status', 'APPROVED')->sum('commission_amount'),
            'paid'     => (float) AgentCommission::where('status', 'PAID')->sum('commission_amount'),
        ];

        $rules = CommissionRule::with('product')->where('is_active', true)->get();

        return Inertia::render('Finance/Commissions', [
            'commissions' => $commissions,
            'stats'       => $stats,
            'rules'       => $rules,
            'filters'     => $request->only(['status', 'agent_id']),
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
            'product_id'      => ['nullable', 'exists:products,id'],
            'rate_type'       => ['required', 'in:PERCENTAGE,FIXED'],
            'rate_value'      => ['required', 'numeric', 'min:0'],
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
            'pending_amount'  => (float) CodSettlement::where('status', 'PENDING')->sum('net_amount'),
            'received_amount' => (float) CodSettlement::where('status', 'RECEIVED')->sum('net_amount'),
            'total_collected'  => (float) CodSettlement::sum('total_cod_collected'),
        ];

        return Inertia::render('Finance/CodSettlements', [
            'settlements' => $settlements,
            'stats'       => $stats,
        ]);
    }

    public function storeCodSettlement(Request $request)
    {
        $validated = $request->validate([
            'courier_code'       => ['required', 'string'],
            'reference_number'   => ['nullable', 'string'],
            'period_start'       => ['required', 'date'],
            'period_end'         => ['required', 'date', 'after_or_equal:period_start'],
            'total_cod_collected' => ['required', 'numeric', 'min:0'],
            'courier_fee'        => ['required', 'numeric', 'min:0'],
            'order_count'        => ['required', 'integer', 'min:0'],
            'notes'              => ['nullable', 'string'],
        ]);

        $validated['net_amount'] = $validated['total_cod_collected'] - $validated['courier_fee'];
        $validated['status'] = 'PENDING';

        CodSettlement::create($validated);

        return back()->with('success', 'COD settlement recorded.');
    }

    public function receiveCodSettlement(CodSettlement $settlement)
    {
        $settlement->update([
            'status'      => 'RECEIVED',
            'received_at' => now(),
        ]);

        return back()->with('success', 'Settlement marked as received.');
    }
}
