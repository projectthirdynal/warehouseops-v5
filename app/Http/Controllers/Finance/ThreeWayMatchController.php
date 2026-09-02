<?php

namespace App\Http\Controllers\Finance;

use Modules\Finance\Services\ThreeWayMatchService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ThreeWayMatchController extends Controller
{
    public function __construct(
        private readonly ThreeWayMatchService $service,
    ) {}

    public function index(Request $request)
    {
        $filters = $request->only(['status']);

        $data = $this->service->getDashboardData($filters);
        $eligiblePos = $this->service->getEligiblePos();

        return Inertia::render('Finance/ThreeWayMatch/Dashboard', [
            'matches' => $data['matches'],
            'stats' => $data['stats'],
            'eligible_pos' => $eligiblePos,
            'filters' => $filters,
        ]);
    }

    public function show(int $matchId)
    {
        $detail = $this->service->getMatchDetail($matchId);

        return Inertia::render('Finance/ThreeWayMatch/Detail', [
            'detail' => $detail,
        ]);
    }

    public function runMatch(Request $request)
    {
        $validated = $request->validate([
            'po_id' => 'required|exists:purchase_orders,id',
            'supplier_invoice_id' => 'nullable|exists:supplier_invoices,id',
        ]);

        $match = $this->service->runMatch(
            (int) $validated['po_id'],
            $validated['supplier_invoice_id'] ? (int) $validated['supplier_invoice_id'] : null,
            $request->user()->id,
        );

        $mismatchCount = $match->mismatches ? count($match->mismatches) : 0;

        return redirect()->route('finance.three-way-match.show', $match->id)
            ->with('success', "Match completed: {$match->status} ({$mismatchCount} mismatch(es)).");
    }

    public function apiStats()
    {
        return response()->json($this->service->getStats());
    }
}
