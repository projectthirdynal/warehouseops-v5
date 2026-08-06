<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Domain\Finance\Models\Budget;
use App\Domain\Finance\Services\BudgetService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BudgetController extends Controller
{
    public function __construct(
        private readonly BudgetService $service,
    ) {}

    public function index(Request $request)
    {
        $filters = $request->only(['department', 'status', 'period_type']);
        $data = $this->service->getDashboardData($filters);

        return Inertia::render('Finance/BudgetVsActual', [
            'budgets' => $data['budgets'],
            'departments' => $data['departments'],
            'activeComparisons' => $data['active_comparisons'],
            'unresolvedAlerts' => $data['unresolved_alerts'],
            'alertSummary' => $data['alert_summary'],
            'filters' => $filters,
        ]);
    }

    public function show(Budget $budget)
    {
        $budget = $this->service->getBudget($budget);
        $comparison = $this->service->getBudgetComparison($budget);
        $alerts = $this->service->getAlerts($budget);

        return Inertia::render('Finance/BudgetVsActual', [
            'budget' => $budget,
            'comparison' => $comparison,
            'alerts' => $alerts,
            'budgets' => $this->service->listBudgets()->map(fn ($b) => [
                'id' => $b->id,
                'department' => $b->department,
                'name' => $b->name,
                'period_type' => $b->period_type,
                'period_start' => $b->period_start->toDateString(),
                'period_end' => $b->period_end->toDateString(),
                'status' => $b->status,
                'line_count' => $b->lines->count(),
                'total_budgeted' => (float) $b->lines->sum('budgeted_amount'),
            ]),
            'departments' => Budget::select('department')->distinct()->orderBy('department')->pluck('department'),
            'activeComparisons' => [],
            'unresolvedAlerts' => [],
            'alertSummary' => ['total' => 0, 'critical' => 0, 'warning' => 0],
            'filters' => [],
        ]);
    }

    public function apiIndex(Request $request)
    {
        $filters = $request->only(['department', 'status', 'period_type']);

        return response()->json($this->service->getDashboardData($filters));
    }

    public function apiShow(Budget $budget)
    {
        $comparison = $this->service->getBudgetComparison($budget);
        $alerts = $this->service->getAlerts($budget);

        return response()->json([
            'budget' => $budget->load('lines'),
            'comparison' => $comparison,
            'alerts' => $alerts,
        ]);
    }

    public function apiStore(Request $request)
    {
        $validated = $request->validate([
            'department' => 'required|string|max:80',
            'name' => 'required|string|max:200',
            'period_type' => 'required|in:MONTHLY,QUARTERLY,YEARLY',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
            'status' => 'nullable|in:DRAFT,ACTIVE,CLOSED',
            'notes' => 'nullable|string|max:2000',
            'lines' => 'nullable|array',
            'lines.*.category' => 'required|string|max:60',
            'lines.*.line_type' => 'required|in:INCOME,EXPENSE',
            'lines.*.budgeted_amount' => 'required|numeric|min:0',
            'lines.*.threshold_percent' => 'nullable|numeric|min:0|max:100',
            'lines.*.notes' => 'nullable|string|max:500',
        ]);

        $budget = $this->service->createBudget($validated, $request->user()->id);

        return response()->json($budget, 201);
    }

    public function apiUpdate(Request $request, Budget $budget)
    {
        $validated = $request->validate([
            'department' => 'sometimes|string|max:80',
            'name' => 'sometimes|string|max:200',
            'period_type' => 'sometimes|in:MONTHLY,QUARTERLY,YEARLY',
            'period_start' => 'sometimes|date',
            'period_end' => 'sometimes|date|after_or_equal:period_start',
            'status' => 'sometimes|in:DRAFT,ACTIVE,CLOSED',
            'notes' => 'nullable|string|max:2000',
            'lines' => 'nullable|array',
            'lines.*.category' => 'required|string|max:60',
            'lines.*.line_type' => 'required|in:INCOME,EXPENSE',
            'lines.*.budgeted_amount' => 'required|numeric|min:0',
            'lines.*.threshold_percent' => 'nullable|numeric|min:0|max:100',
            'lines.*.notes' => 'nullable|string|max:500',
        ]);

        $budget = $this->service->updateBudget($budget, $validated);

        return response()->json($budget);
    }

    public function apiDestroy(Budget $budget)
    {
        $deleted = $this->service->deleteBudget($budget);

        return response()->json(['deleted' => $deleted]);
    }

    public function apiComparison(Budget $budget)
    {
        return response()->json($this->service->getBudgetComparison($budget));
    }

    public function apiGenerateAlerts(Budget $budget)
    {
        $alerts = $this->service->generateVarianceAlerts($budget);

        return response()->json([
            'generated' => count($alerts),
            'alerts' => $alerts,
        ]);
    }

    public function apiResolveAlert(Request $request, int $alertId)
    {
        $resolved = $this->service->resolveAlert($alertId, $request->user()->id);

        return response()->json(['resolved' => $resolved]);
    }

    public function apiAlerts(Budget $budget)
    {
        return response()->json($this->service->getAlerts($budget));
    }
}
