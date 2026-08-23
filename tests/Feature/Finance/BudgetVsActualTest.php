<?php

use App\Domain\Finance\Models\Budget;
use App\Domain\Finance\Models\BudgetLine;
use App\Domain\Finance\Models\BudgetVarianceAlert;
use App\Domain\Finance\Models\FinancialTransaction;
use App\Domain\Finance\Services\BudgetService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\VerifyCsrfToken;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['role' => 'superadmin', 'is_active' => true]);
    $this->service = app(BudgetService::class);
    $this->withoutMiddleware([VerifyCsrfToken::class]);
});

describe('BudgetService', function () {
    it('creates a budget with lines', function () {
        $budget = $this->service->createBudget([
            'department' => 'Operations',
            'name' => 'Q1 2026 Budget',
            'period_type' => 'QUARTERLY',
            'period_start' => '2026-01-01',
            'period_end' => '2026-03-31',
            'status' => 'ACTIVE',
            'lines' => [
                ['category' => 'REVENUE', 'line_type' => 'INCOME', 'budgeted_amount' => 500000],
                ['category' => 'COGS', 'line_type' => 'EXPENSE', 'budgeted_amount' => 200000],
                ['category' => 'SHIPPING', 'line_type' => 'EXPENSE', 'budgeted_amount' => 50000],
            ],
        ], $this->user->id);

        expect($budget)->toBeInstanceOf(Budget::class);
        expect($budget->department)->toBe('Operations');
        expect($budget->status)->toBe('ACTIVE');
        expect($budget->lines)->toHaveCount(3);
        expect($budget->lines->pluck('category')->contains('REVENUE'))->toBeTrue();
    });

    it('updates a budget and its lines', function () {
        $budget = $this->service->createBudget([
            'department' => 'Sales',
            'name' => 'Original',
            'period_type' => 'MONTHLY',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'lines' => [
                ['category' => 'REVENUE', 'line_type' => 'INCOME', 'budgeted_amount' => 100000],
            ],
        ], $this->user->id);

        $updated = $this->service->updateBudget($budget, [
            'name' => 'Updated Budget',
            'status' => 'ACTIVE',
            'lines' => [
                ['category' => 'REVENUE', 'line_type' => 'INCOME', 'budgeted_amount' => 150000],
                ['category' => 'COMMISSIONS', 'line_type' => 'EXPENSE', 'budgeted_amount' => 20000],
            ],
        ]);

        expect($updated->name)->toBe('Updated Budget');
        expect($updated->status)->toBe('ACTIVE');
        expect($updated->lines)->toHaveCount(2);
        expect((float) $updated->lines->firstWhere('category', 'REVENUE')->budgeted_amount)->toBe(150000.0);
    });

    it('deletes a budget', function () {
        $budget = $this->service->createBudget([
            'department' => 'Temp',
            'name' => 'To Delete',
            'period_type' => 'MONTHLY',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'lines' => [],
        ], $this->user->id);

        $deleted = $this->service->deleteBudget($budget);

        expect($deleted)->toBeTrue();
        expect(Budget::find($budget->id))->toBeNull();
    });

    it('lists budgets with filters', function () {
        $this->service->createBudget([
            'department' => 'Ops',
            'name' => 'Active Budget',
            'period_type' => 'MONTHLY',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => 'ACTIVE',
            'lines' => [],
        ], $this->user->id);

        $this->service->createBudget([
            'department' => 'Ops',
            'name' => 'Draft Budget',
            'period_type' => 'MONTHLY',
            'period_start' => '2026-02-01',
            'period_end' => '2026-02-28',
            'status' => 'DRAFT',
            'lines' => [],
        ], $this->user->id);

        $all = $this->service->listBudgets(['department' => 'Ops']);
        expect($all)->toHaveCount(2);

        $active = $this->service->listBudgets(['status' => 'ACTIVE']);
        expect($active)->toHaveCount(1);
        expect($active->first()->name)->toBe('Active Budget');
    });

    it('computes budget vs actual comparison with revenue transactions', function () {
        $budget = $this->service->createBudget([
            'department' => 'Sales',
            'name' => 'Jan Budget',
            'period_type' => 'MONTHLY',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => 'ACTIVE',
            'lines' => [
                ['category' => 'REVENUE', 'line_type' => 'INCOME', 'budgeted_amount' => 100000, 'threshold_percent' => 10],
            ],
        ], $this->user->id);

        FinancialTransaction::create([
            'type' => 'REVENUE',
            'amount' => 80000,
            'description' => 'Test revenue',
            'transaction_date' => '2026-01-15',
        ]);

        $comparison = $this->service->getBudgetComparison($budget);

        expect($comparison['lines'])->toHaveCount(1);
        expect($comparison['lines'][0]['category'])->toBe('REVENUE');
        expect($comparison['lines'][0]['actual_amount'])->toBe(80000.0);
        expect($comparison['lines'][0]['budgeted_amount'])->toBe(100000.0);
        expect($comparison['lines'][0]['variance_amount'])->toBe(-20000.0);
        expect($comparison['lines'][0]['variance_percent'])->toBe(-20.0);
        expect($comparison['lines'][0]['is_over_threshold'])->toBeTrue();
    });

    it('computes expense variance correctly (budgeted - actual)', function () {
        $budget = $this->service->createBudget([
            'department' => 'Ops',
            'name' => 'Jan Ops',
            'period_type' => 'MONTHLY',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => 'ACTIVE',
            'lines' => [
                ['category' => 'SHIPPING', 'line_type' => 'EXPENSE', 'budgeted_amount' => 50000, 'threshold_percent' => 10],
            ],
        ], $this->user->id);

        FinancialTransaction::create([
            'type' => 'SHIPPING_COST',
            'amount' => -60000,
            'description' => 'Shipping',
            'transaction_date' => '2026-01-20',
        ]);

        $comparison = $this->service->getBudgetComparison($budget);

        expect($comparison['lines'][0]['actual_amount'])->toBe(60000.0);
        expect($comparison['lines'][0]['variance_amount'])->toBe(-10000.0);
        expect($comparison['lines'][0]['variance_percent'])->toBe(-20.0);
    });

    it('generates variance alerts for over-threshold lines', function () {
        $budget = $this->service->createBudget([
            'department' => 'Ops',
            'name' => 'Alert Test',
            'period_type' => 'MONTHLY',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => 'ACTIVE',
            'lines' => [
                ['category' => 'REVENUE', 'line_type' => 'INCOME', 'budgeted_amount' => 100000, 'threshold_percent' => 10],
                ['category' => 'SHIPPING', 'line_type' => 'EXPENSE', 'budgeted_amount' => 50000, 'threshold_percent' => 10],
            ],
        ], $this->user->id);

        FinancialTransaction::create([
            'type' => 'REVENUE',
            'amount' => 70000,
            'description' => 'Low revenue',
            'transaction_date' => '2026-01-15',
        ]);

        FinancialTransaction::create([
            'type' => 'SHIPPING_COST',
            'amount' => -70000,
            'description' => 'High shipping',
            'transaction_date' => '2026-01-20',
        ]);

        $alerts = $this->service->generateVarianceAlerts($budget);

        expect($alerts)->toHaveCount(2);
        expect($alerts[0]->severity)->toBe('CRITICAL');
        expect(BudgetVarianceAlert::count())->toBe(2);
    });

    it('does not generate alerts when within threshold', function () {
        $budget = $this->service->createBudget([
            'department' => 'Ops',
            'name' => 'OK Budget',
            'period_type' => 'MONTHLY',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => 'ACTIVE',
            'lines' => [
                ['category' => 'REVENUE', 'line_type' => 'INCOME', 'budgeted_amount' => 100000, 'threshold_percent' => 10],
            ],
        ], $this->user->id);

        FinancialTransaction::create([
            'type' => 'REVENUE',
            'amount' => 95000,
            'description' => 'Close to budget',
            'transaction_date' => '2026-01-15',
        ]);

        $alerts = $this->service->generateVarianceAlerts($budget);

        expect($alerts)->toBeEmpty();
    });

    it('resolves a variance alert', function () {
        $budget = $this->service->createBudget([
            'department' => 'Ops',
            'name' => 'Resolve Test',
            'period_type' => 'MONTHLY',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => 'ACTIVE',
            'lines' => [
                ['category' => 'REVENUE', 'line_type' => 'INCOME', 'budgeted_amount' => 100000, 'threshold_percent' => 5],
            ],
        ], $this->user->id);

        FinancialTransaction::create([
            'type' => 'REVENUE',
            'amount' => 50000,
            'description' => 'Very low',
            'transaction_date' => '2026-01-15',
        ]);

        $alerts = $this->service->generateVarianceAlerts($budget);
        $alertId = $alerts[0]->id;

        $resolved = $this->service->resolveAlert($alertId, $this->user->id);

        expect($resolved)->toBeTrue();
        $alert = BudgetVarianceAlert::find($alertId);
        expect($alert->is_resolved)->toBeTrue();
        expect($alert->resolved_at)->not->toBeNull();
        expect($alert->resolved_by)->toBe($this->user->id);
    });

    it('gets dashboard data with active comparisons', function () {
        $this->service->createBudget([
            'department' => 'Ops',
            'name' => 'Active',
            'period_type' => 'MONTHLY',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => 'ACTIVE',
            'lines' => [
                ['category' => 'REVENUE', 'line_type' => 'INCOME', 'budgeted_amount' => 100000],
            ],
        ], $this->user->id);

        $this->service->createBudget([
            'department' => 'Ops',
            'name' => 'Draft',
            'period_type' => 'MONTHLY',
            'period_start' => '2026-02-01',
            'period_end' => '2026-02-28',
            'status' => 'DRAFT',
            'lines' => [],
        ], $this->user->id);

        $data = $this->service->getDashboardData();

        expect($data['budgets'])->toHaveCount(2);
        expect($data['departments'])->toContain('Ops');
        expect($data['active_comparisons'])->toHaveCount(1);
        expect($data['active_comparisons'][0]['budget']['name'])->toBe('Active');
    });
});

describe('Budget vs Actual API Endpoints', function () {
    it('GET /finance/budget renders the page', function () {
        $response = $this->actingAs($this->user)->get('/finance/budget');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('budgets')
            ->has('departments')
            ->has('activeComparisons')
            ->has('unresolvedAlerts')
            ->has('alertSummary')
        );
    });

    it('GET /finance/budget/api returns dashboard data', function () {
        $response = $this->actingAs($this->user)->getJson('/finance/budget/api');

        $response->assertOk();
        $response->assertJsonStructure([
            'budgets',
            'departments',
            'active_comparisons',
            'unresolved_alerts',
            'alert_summary',
        ]);
    });

    it('POST /finance/budget/api creates a budget', function () {
        $response = $this->actingAs($this->user)->postJson('/finance/budget/api', [
            'department' => 'Operations',
            'name' => 'Test Budget',
            'period_type' => 'MONTHLY',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => 'ACTIVE',
            'lines' => [
                ['category' => 'REVENUE', 'line_type' => 'INCOME', 'budgeted_amount' => 100000],
                ['category' => 'COGS', 'line_type' => 'EXPENSE', 'budgeted_amount' => 40000],
            ],
        ]);

        $response->assertCreated();
        $response->assertJson(['department' => 'Operations', 'name' => 'Test Budget']);
        expect(Budget::count())->toBe(1);
        expect(BudgetLine::count())->toBe(2);
    });

    it('PUT /finance/budget/api/{budget} updates a budget', function () {
        $budget = $this->service->createBudget([
            'department' => 'Ops',
            'name' => 'Original',
            'period_type' => 'MONTHLY',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'lines' => [],
        ], $this->user->id);

        $response = $this->actingAs($this->user)->putJson("/finance/budget/api/{$budget->id}", [
            'name' => 'Updated Name',
            'status' => 'ACTIVE',
        ]);

        $response->assertOk();
        $response->assertJson(['name' => 'Updated Name', 'status' => 'ACTIVE']);
    });

    it('DELETE /finance/budget/api/{budget} deletes a budget', function () {
        $budget = $this->service->createBudget([
            'department' => 'Ops',
            'name' => 'To Delete',
            'period_type' => 'MONTHLY',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'lines' => [],
        ], $this->user->id);

        $response = $this->actingAs($this->user)->deleteJson("/finance/budget/api/{$budget->id}");

        $response->assertOk();
        $response->assertJson(['deleted' => true]);
        expect(Budget::find($budget->id))->toBeNull();
    });

    it('GET /finance/budget/api/{budget}/comparison returns comparison', function () {
        $budget = $this->service->createBudget([
            'department' => 'Ops',
            'name' => 'Comparison Test',
            'period_type' => 'MONTHLY',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => 'ACTIVE',
            'lines' => [
                ['category' => 'REVENUE', 'line_type' => 'INCOME', 'budgeted_amount' => 100000],
            ],
        ], $this->user->id);

        $response = $this->actingAs($this->user)->getJson("/finance/budget/api/{$budget->id}/comparison");

        $response->assertOk();
        $response->assertJsonStructure([
            'budget',
            'lines',
            'summary',
        ]);
    });

    it('POST /finance/budget/api/{budget}/generate-alerts creates alerts', function () {
        $budget = $this->service->createBudget([
            'department' => 'Ops',
            'name' => 'Alert Gen',
            'period_type' => 'MONTHLY',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => 'ACTIVE',
            'lines' => [
                ['category' => 'REVENUE', 'line_type' => 'INCOME', 'budgeted_amount' => 100000, 'threshold_percent' => 5],
            ],
        ], $this->user->id);

        FinancialTransaction::create([
            'type' => 'REVENUE',
            'amount' => 50000,
            'description' => 'Low',
            'transaction_date' => '2026-01-15',
        ]);

        $response = $this->actingAs($this->user)->postJson("/finance/budget/api/{$budget->id}/generate-alerts");

        $response->assertOk();
        $response->assertJsonStructure(['generated', 'alerts']);
        $response->assertJson(['generated' => 1]);
    });

    it('PATCH /finance/budget/api/alerts/{alertId}/resolve resolves alert', function () {
        $budget = $this->service->createBudget([
            'department' => 'Ops',
            'name' => 'Resolve API',
            'period_type' => 'MONTHLY',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => 'ACTIVE',
            'lines' => [
                ['category' => 'REVENUE', 'line_type' => 'INCOME', 'budgeted_amount' => 100000, 'threshold_percent' => 5],
            ],
        ], $this->user->id);

        FinancialTransaction::create([
            'type' => 'REVENUE',
            'amount' => 50000,
            'description' => 'Low',
            'transaction_date' => '2026-01-15',
        ]);

        $alerts = $this->service->generateVarianceAlerts($budget);
        $alertId = $alerts[0]->id;

        $response = $this->actingAs($this->user)->patchJson("/finance/budget/api/alerts/{$alertId}/resolve");

        $response->assertOk();
        $response->assertJson(['resolved' => true]);
    });

    it('GET /finance/budget/api/{budget}/alerts returns alerts', function () {
        $budget = $this->service->createBudget([
            'department' => 'Ops',
            'name' => 'Alerts List',
            'period_type' => 'MONTHLY',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => 'ACTIVE',
            'lines' => [
                ['category' => 'REVENUE', 'line_type' => 'INCOME', 'budgeted_amount' => 100000, 'threshold_percent' => 5],
            ],
        ], $this->user->id);

        FinancialTransaction::create([
            'type' => 'REVENUE',
            'amount' => 50000,
            'description' => 'Low',
            'transaction_date' => '2026-01-15',
        ]);

        $this->service->generateVarianceAlerts($budget);

        $response = $this->actingAs($this->user)->getJson("/finance/budget/api/{$budget->id}/alerts");

        $response->assertOk();
        $response->assertJsonCount(1);
    });
});
