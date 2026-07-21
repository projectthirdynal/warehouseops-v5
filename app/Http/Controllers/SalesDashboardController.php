<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Analytics\Services\SalesDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalesDashboardController extends Controller
{
    public function __construct(
        private readonly SalesDashboardService $service,
    ) {}

    public function index(): Response
    {
        return Inertia::render('SalesDashboard/Index', [
            'orderCounts' => $this->service->orderCounts(),
            'revenueTotals' => $this->service->revenueTotals(),
            'statusBreakdown' => $this->service->statusBreakdown(),
            'topProducts' => $this->service->topProducts(),
            'salesTrends' => $this->service->salesTrends(),
            'revenueBySource' => $this->service->revenueBySource(),
            'revenueByPaymentMethod' => $this->service->revenueByPaymentMethod(),
            'agentLeaderboard' => $this->service->agentLeaderboard(),
            'cohortRetention' => $this->service->cohortRetention(),
            'averageOrderValue' => $this->service->averageOrderValue(),
            'returnRefundRate' => $this->service->returnRefundRate(),
            'predictiveInsights' => $this->service->predictiveSalesInsights(),
        ]);
    }

    public function apiOrderCounts(): JsonResponse
    {
        return response()->json([
            'order_counts' => $this->service->orderCounts(),
        ]);
    }

    public function apiRevenueTotals(): JsonResponse
    {
        return response()->json([
            'revenue_totals' => $this->service->revenueTotals(),
        ]);
    }

    public function apiStatusBreakdown(): JsonResponse
    {
        return response()->json([
            'status_breakdown' => $this->service->statusBreakdown(),
        ]);
    }

    public function apiTopProducts(): JsonResponse
    {
        $limit = (int) request()->query('limit', 10);
        return response()->json([
            'top_products' => $this->service->topProducts($limit),
        ]);
    }

    public function apiSalesTrends(): JsonResponse
    {
        $period = (string) request()->query('period', 'daily');
        $points = (int) request()->query('points', 90);
        return response()->json([
            'sales_trends' => $this->service->salesTrends($period, $points),
        ]);
    }

    public function apiRevenueBySource(): JsonResponse
    {
        return response()->json([
            'revenue_by_source' => $this->service->revenueBySource(),
        ]);
    }

    public function apiRevenueByPaymentMethod(): JsonResponse
    {
        return response()->json([
            'revenue_by_payment_method' => $this->service->revenueByPaymentMethod(),
        ]);
    }

    public function apiAgentLeaderboard(): JsonResponse
    {
        $limit = (int) request()->query('limit', 10);
        return response()->json([
            'agent_leaderboard' => $this->service->agentLeaderboard($limit),
        ]);
    }

    public function apiCohortRetention(): JsonResponse
    {
        $cohortMonths = (int) request()->query('cohort_months', 12);
        $retentionMonths = (int) request()->query('retention_months', 6);
        return response()->json([
            'cohort_retention' => $this->service->cohortRetention($cohortMonths, $retentionMonths),
        ]);
    }

    public function apiAverageOrderValue(): JsonResponse
    {
        return response()->json([
            'average_order_value' => $this->service->averageOrderValue(),
        ]);
    }

    public function apiReturnRefundRate(): JsonResponse
    {
        return response()->json([
            'return_refund_rate' => $this->service->returnRefundRate(),
        ]);
    }

    public function apiSalesReport(): JsonResponse
    {
        $from = request()->query('from');
        $to = request()->query('to');
        return response()->json([
            'sales_report' => $this->service->exportSalesReport(
                $from ? (string) $from : null,
                $to ? (string) $to : null,
            ),
        ]);
    }

    public function downloadSalesReport(): StreamedResponse
    {
        $from = request()->query('from');
        $to = request()->query('to');
        $csv = $this->service->exportSalesReportCsv(
            $from ? (string) $from : null,
            $to ? (string) $to : null,
        );

        $fromStr = $from ? Carbon::parse($from)->format('Ymd') : 'all';
        $toStr = $to ? Carbon::parse($to)->format('Ymd') : 'now';
        $filename = "sales_report_{$fromStr}_{$toStr}.csv";

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function apiPredictiveInsights(): JsonResponse
    {
        $forecastDays = (int) request()->query('forecast_days', 30);
        return response()->json([
            'predictive_insights' => $this->service->predictiveSalesInsights($forecastDays),
        ]);
    }
}
