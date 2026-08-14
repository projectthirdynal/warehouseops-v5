<?php

use App\Domain\Finance\Services\FinanceDashboardService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\VerifyCsrfToken;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['role' => 'superadmin', 'is_active' => true]);
    $this->service = app(FinanceDashboardService::class);
    $this->withoutMiddleware([VerifyCsrfToken::class]);
});

describe('FinanceDashboardService', function () {
    it('gets cash flow data for a date range', function () {
        $from = now()->startOfMonth();
        $to = now()->endOfDay();

        $result = $this->service->getCashFlow($from, $to);

        expect($result)->toHaveKeys(['inflows', 'outflows', 'net_cash_flow', 'trend']);
        expect($result['inflows'])->toHaveKeys(['revenue', 'cod_received', 'gateway', 'invoice_payments', 'total']);
        expect($result['outflows'])->toHaveKeys(['supplier_payments', 'shipping', 'commissions', 'courier_fees', 'total']);
        expect($result['inflows']['total'])->toBeFloat();
        expect($result['outflows']['total'])->toBeFloat();
        expect($result['net_cash_flow'])->toBeFloat();
        expect($result['trend'])->toBeArray();
    });

    it('gets P&L trend for 6 months', function () {
        $result = $this->service->getPlTrend(6);

        expect($result)->toBeArray();
        expect(count($result))->toBe(6);
        expect($result[0])->toHaveKeys(['month', 'revenue', 'cogs', 'gross_profit', 'shipping', 'commissions', 'net_profit', 'margin']);
    });

    it('gets balance sheet with assets, liabilities, and equity', function () {
        $result = $this->service->getBalanceSheet();

        expect($result)->toHaveKeys(['assets', 'liabilities', 'equity', 'total']);
        expect($result['assets'])->toHaveKeys(['inventory', 'accounts_receivable', 'cod_in_transit', 'capex_assets', 'cash_on_hand', 'total']);
        expect($result['liabilities'])->toHaveKeys(['accounts_payable', 'commissions_payable', 'total']);
        expect($result['equity'])->toBeFloat();
        expect($result['assets']['total'] - $result['liabilities']['total'])->toBe($result['equity']);
    });

    it('gets revenue trends with period-over-period comparison', function () {
        $from = now()->startOfMonth();
        $to = now()->endOfDay();

        $result = $this->service->getRevenueTrends($from, $to);

        expect($result)->toHaveKeys(['current', 'previous', 'growth_pct', 'trend', 'by_source']);
        expect($result['current'])->toHaveKeys(['gross_revenue', 'refunds', 'net_revenue']);
        expect($result['previous'])->toHaveKeys(['gross_revenue', 'refunds', 'net_revenue']);
        expect($result['growth_pct'])->toBeFloat();
        expect($result['trend'])->toBeArray();
        expect($result['by_source'])->toBeArray();
    });

    it('gets complete dashboard data', function () {
        $from = now()->startOfMonth();
        $to = now()->endOfDay();

        $result = $this->service->getDashboardData($from, $to);

        expect($result)->toHaveKeys(['cash_flow', 'pl_trend', 'balance_sheet', 'revenue_trends']);
    });
});

describe('Finance Dashboard API Endpoints', function () {
    it('GET /finance/api/dashboard returns enhanced dashboard data', function () {
        $response = $this->actingAs($this->user)
            ->getJson('/finance/api/dashboard');

        $response->assertOk();
        $response->assertJsonStructure([
            'cash_flow' => ['inflows', 'outflows', 'net_cash_flow', 'trend'],
            'pl_trend',
            'balance_sheet' => ['assets', 'liabilities', 'equity', 'total'],
            'revenue_trends' => ['current', 'previous', 'growth_pct', 'trend', 'by_source'],
        ]);
    });

    it('GET /finance/api/cash-flow returns cash flow data', function () {
        $response = $this->actingAs($this->user)
            ->getJson('/finance/api/cash-flow');

        $response->assertOk();
        $response->assertJsonStructure([
            'inflows' => ['revenue', 'cod_received', 'gateway', 'invoice_payments', 'total'],
            'outflows' => ['supplier_payments', 'shipping', 'commissions', 'courier_fees', 'total'],
            'net_cash_flow',
            'trend',
        ]);
    });

    it('GET /finance/api/pl-trend returns P&L trend data', function () {
        $response = $this->actingAs($this->user)
            ->getJson('/finance/api/pl-trend');

        $response->assertOk();
        $response->assertJsonStructure([
            '*' => ['month', 'revenue', 'cogs', 'gross_profit', 'shipping', 'commissions', 'net_profit', 'margin'],
        ]);
    });

    it('GET /finance/api/balance-sheet returns balance sheet data', function () {
        $response = $this->actingAs($this->user)
            ->getJson('/finance/api/balance-sheet');

        $response->assertOk();
        $response->assertJsonStructure([
            'assets' => ['inventory', 'accounts_receivable', 'cod_in_transit', 'capex_assets', 'cash_on_hand', 'total'],
            'liabilities' => ['accounts_payable', 'commissions_payable', 'total'],
            'equity',
            'total',
        ]);
    });

    it('GET /finance/api/revenue-trends returns revenue trends data', function () {
        $response = $this->actingAs($this->user)
            ->getJson('/finance/api/revenue-trends');

        $response->assertOk();
        $response->assertJsonStructure([
            'current' => ['gross_revenue', 'refunds', 'net_revenue'],
            'previous' => ['gross_revenue', 'refunds', 'net_revenue'],
            'growth_pct',
            'trend',
            'by_source',
        ]);
    });

    it('GET /finance dashboard renders with enhanced data', function () {
        $response = $this->actingAs($this->user)
            ->get('/finance');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('enhanced.cash_flow')
            ->has('enhanced.pl_trend')
            ->has('enhanced.balance_sheet')
            ->has('enhanced.revenue_trends')
        );
    });
});
