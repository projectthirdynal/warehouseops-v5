<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\Budget;
use App\Domain\Finance\Models\BudgetVarianceAlert;
use App\Domain\Finance\Models\CogsEntry;
use App\Domain\Finance\Models\FinancialTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BudgetService
{
    private const CATEGORY_TRANSACTION_TYPE_MAP = [
        'REVENUE' => 'REVENUE',
        'REFUNDS' => 'REFUND',
        'SHIPPING' => 'SHIPPING_COST',
        'COMMISSIONS' => 'COMMISSION',
        'OTHER_EXPENSE' => 'OTHER_EXPENSE',
    ];

    public function createBudget(array $data, int $userId): Budget
    {
        return DB::transaction(function () use ($data, $userId) {
            $budget = Budget::create([
                'department' => $data['department'],
                'name' => $data['name'],
                'period_type' => $data['period_type'],
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
                'status' => $data['status'] ?? 'DRAFT',
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            if (isset($data['lines']) && is_array($data['lines'])) {
                foreach ($data['lines'] as $line) {
                    $budget->lines()->create([
                        'category' => $line['category'],
                        'line_type' => $line['line_type'],
                        'budgeted_amount' => $line['budgeted_amount'],
                        'threshold_percent' => $line['threshold_percent'] ?? 10.00,
                        'notes' => $line['notes'] ?? null,
                    ]);
                }
            }

            return $budget->load('lines');
        });
    }

    public function updateBudget(Budget $budget, array $data): Budget
    {
        $budget->update([
            'department' => $data['department'] ?? $budget->department,
            'name' => $data['name'] ?? $budget->name,
            'period_type' => $data['period_type'] ?? $budget->period_type,
            'period_start' => $data['period_start'] ?? $budget->period_start,
            'period_end' => $data['period_end'] ?? $budget->period_end,
            'status' => $data['status'] ?? $budget->status,
            'notes' => $data['notes'] ?? $budget->notes,
        ]);

        if (isset($data['lines']) && is_array($data['lines'])) {
            $budget->lines()->delete();
            foreach ($data['lines'] as $line) {
                $budget->lines()->create([
                    'category' => $line['category'],
                    'line_type' => $line['line_type'],
                    'budgeted_amount' => $line['budgeted_amount'],
                    'threshold_percent' => $line['threshold_percent'] ?? 10.00,
                    'notes' => $line['notes'] ?? null,
                ]);
            }
        }

        return $budget->fresh('lines');
    }

    public function deleteBudget(Budget $budget): bool
    {
        return $budget->delete();
    }

    public function getBudget(Budget $budget): Budget
    {
        return $budget->load(['lines', 'varianceAlerts.unresolved', 'creator:id,name']);
    }

    public function listBudgets(array $filters = []): Collection
    {
        $query = Budget::with(['lines', 'creator:id,name']);

        if (isset($filters['department'])) {
            $query->where('department', $filters['department']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['period_type'])) {
            $query->where('period_type', $filters['period_type']);
        }

        return $query->orderByDesc('period_start')->get();
    }

    public function getBudgetComparison(Budget $budget): array
    {
        $from = Carbon::parse($budget->period_start);
        $to = Carbon::parse($budget->period_end);

        $lines = $budget->lines;
        $comparison = [];
        $totalBudgetedIncome = 0.0;
        $totalActualIncome = 0.0;
        $totalBudgetedExpense = 0.0;
        $totalActualExpense = 0.0;

        foreach ($lines as $line) {
            $actual = $this->computeActual($line->category, $from, $to);
            $budgeted = (float) $line->budgeted_amount;
            $variance = $line->line_type === 'INCOME'
                ? $actual - $budgeted
                : $budgeted - $actual;
            $variancePercent = $budgeted > 0
                ? round(($variance / $budgeted) * 100, 2)
                : 0.0;

            $comparison[] = [
                'id' => $line->id,
                'category' => $line->category,
                'line_type' => $line->line_type,
                'budgeted_amount' => $budgeted,
                'actual_amount' => $actual,
                'variance_amount' => round($variance, 2),
                'variance_percent' => $variancePercent,
                'threshold_percent' => (float) $line->threshold_percent,
                'is_over_threshold' => abs($variancePercent) > (float) $line->threshold_percent,
                'notes' => $line->notes,
            ];

            if ($line->line_type === 'INCOME') {
                $totalBudgetedIncome += $budgeted;
                $totalActualIncome += $actual;
            } else {
                $totalBudgetedExpense += $budgeted;
                $totalActualExpense += $actual;
            }
        }

        $netBudgeted = $totalBudgetedIncome - $totalBudgetedExpense;
        $netActual = $totalActualIncome - $totalActualExpense;
        $netVariance = $netActual - $netBudgeted;

        return [
            'budget' => [
                'id' => $budget->id,
                'department' => $budget->department,
                'name' => $budget->name,
                'period_type' => $budget->period_type,
                'period_start' => $budget->period_start->toDateString(),
                'period_end' => $budget->period_end->toDateString(),
                'status' => $budget->status,
            ],
            'lines' => $comparison,
            'summary' => [
                'total_budgeted_income' => round($totalBudgetedIncome, 2),
                'total_actual_income' => round($totalActualIncome, 2),
                'total_budgeted_expense' => round($totalBudgetedExpense, 2),
                'total_actual_expense' => round($totalActualExpense, 2),
                'net_budgeted' => round($netBudgeted, 2),
                'net_actual' => round($netActual, 2),
                'net_variance' => round($netVariance, 2),
            ],
        ];
    }

    public function generateVarianceAlerts(Budget $budget): array
    {
        $comparison = $this->getBudgetComparison($budget);
        $created = [];

        foreach ($comparison['lines'] as $line) {
            if (! $line['is_over_threshold']) {
                continue;
            }

            $severity = abs($line['variance_percent']) >= 20 ? 'CRITICAL' : 'WARNING';
            $direction = $line['variance_amount'] < 0 ? 'under' : 'over';

            $message = $line['line_type'] === 'INCOME'
                ? "Income category '{$line['category']}' is {$direction} budget by {$line['variance_percent']}%"
                : "Expense category '{$line['category']}' is {$direction} budget by {$line['variance_percent']}%";

            $alert = BudgetVarianceAlert::create([
                'budget_id' => $budget->id,
                'budget_line_id' => $line['id'],
                'budgeted_amount' => $line['budgeted_amount'],
                'actual_amount' => $line['actual_amount'],
                'variance_amount' => $line['variance_amount'],
                'variance_percent' => $line['variance_percent'],
                'severity' => $severity,
                'message' => $message,
            ]);

            $created[] = $alert;
        }

        return $created;
    }

    public function resolveAlert(int $alertId, int $userId): bool
    {
        $alert = BudgetVarianceAlert::findOrFail($alertId);
        $alert->update([
            'is_resolved' => true,
            'resolved_at' => now(),
            'resolved_by' => $userId,
        ]);

        return true;
    }

    public function getAlerts(Budget $budget): Collection
    {
        return $budget->varianceAlerts()
            ->with(['budgetLine:id,category,line_type', 'resolver:id,name'])
            ->orderByDesc('created_at')
            ->get();
    }

    public function getDashboardData(array $filters = []): array
    {
        $budgets = $this->listBudgets($filters);
        $departments = Budget::select('department')->distinct()->orderBy('department')->pluck('department');

        $activeBudgets = $budgets->where('status', 'ACTIVE');
        $comparisons = [];

        foreach ($activeBudgets as $budget) {
            $comp = $this->getBudgetComparison($budget);
            $comparisons[] = $comp;
        }

        $unresolvedAlerts = BudgetVarianceAlert::with(['budget:id,department,name', 'budgetLine:id,category'])
            ->unresolved()
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return [
            'budgets' => $budgets->map(fn ($b) => [
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
            'departments' => $departments,
            'active_comparisons' => $comparisons,
            'unresolved_alerts' => $unresolvedAlerts,
            'alert_summary' => [
                'total' => $unresolvedAlerts->count(),
                'critical' => $unresolvedAlerts->where('severity', 'CRITICAL')->count(),
                'warning' => $unresolvedAlerts->where('severity', 'WARNING')->count(),
            ],
        ];
    }

    private function computeActual(string $category, Carbon $from, Carbon $to): float
    {
        if ($category === 'COGS') {
            return (float) CogsEntry::whereBetween('recorded_at', [$from, $to])
                ->sum('total_cost');
        }

        $txType = self::CATEGORY_TRANSACTION_TYPE_MAP[$category] ?? null;

        if ($txType === null) {
            return 0.0;
        }

        $query = FinancialTransaction::whereBetween('transaction_date', [$from, $to]);

        if ($txType === 'REVENUE') {
            return (float) $query->where('type', 'REVENUE')->sum('amount');
        }

        return abs((float) $query->where('type', $txType)->sum('amount'));
    }
}
