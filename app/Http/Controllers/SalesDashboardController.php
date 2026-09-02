<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Modules\Analytics\Services\SalesDashboardService;
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
            'widgetConfig' => $this->service->getWidgetConfig(auth()->id() ?? 0),
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

    public function apiWidgetConfig(): JsonResponse
    {
        $dashboard = (string) request()->query('dashboard', 'sales');

        return response()->json([
            'widget_config' => $this->service->getWidgetConfig(auth()->id() ?? 0, $dashboard),
        ]);
    }

    public function apiSaveWidgetConfig(): JsonResponse
    {
        $dashboard = (string) request()->input('dashboard', 'sales');
        $widgets = request()->input('widgets', []);

        return response()->json([
            'widget_config' => $this->service->saveWidgetConfig(auth()->id() ?? 0, $widgets, $dashboard),
        ]);
    }

    public function apiResetWidgetConfig(): JsonResponse
    {
        $dashboard = (string) request()->query('dashboard', 'sales');

        return response()->json([
            'widget_config' => $this->service->resetWidgetConfig(auth()->id() ?? 0, $dashboard),
        ]);
    }

    public function apiListScheduledReports(): JsonResponse
    {
        return response()->json([
            'scheduled_reports' => $this->service->listScheduledReports(auth()->id() ?? 0),
        ]);
    }

    public function apiCreateScheduledReport(): JsonResponse
    {
        $data = request()->validate([
            'name' => 'required|string|max:255',
            'frequency' => 'nullable|string|in:daily,weekly,monthly',
            'send_at' => 'nullable|string',
            'day_of_week' => 'nullable|string|in:sun,mon,tue,wed,thu,fri,sat',
            'day_of_month' => 'nullable|integer|min:1|max:31',
            'format' => 'nullable|string|in:csv,json',
            'lookback_days' => 'nullable|integer|min:1|max:365',
            'recipients' => 'nullable|array',
            'recipients.*' => 'email',
            'is_active' => 'nullable|boolean',
        ]);

        return response()->json([
            'scheduled_report' => $this->service->createScheduledReport(auth()->id() ?? 0, $data),
        ], 201);
    }

    public function apiUpdateScheduledReport(int $id): JsonResponse
    {
        $data = request()->validate([
            'name' => 'sometimes|string|max:255',
            'frequency' => 'sometimes|string|in:daily,weekly,monthly',
            'send_at' => 'sometimes|string',
            'day_of_week' => 'sometimes|nullable|string|in:sun,mon,tue,wed,thu,fri,sat',
            'day_of_month' => 'sometimes|nullable|integer|min:1|max:31',
            'format' => 'sometimes|string|in:csv,json',
            'lookback_days' => 'sometimes|integer|min:1|max:365',
            'recipients' => 'sometimes|nullable|array',
            'recipients.*' => 'email',
            'is_active' => 'sometimes|boolean',
        ]);

        $result = $this->service->updateScheduledReport($id, auth()->id() ?? 0, $data);

        if ($result === null) {
            return response()->json(['error' => 'Scheduled report not found'], 404);
        }

        return response()->json([
            'scheduled_report' => $result,
        ]);
    }

    public function apiDeleteScheduledReport(int $id): JsonResponse
    {
        $deleted = $this->service->deleteScheduledReport($id, auth()->id() ?? 0);

        if (! $deleted) {
            return response()->json(['error' => 'Scheduled report not found'], 404);
        }

        return response()->json(['deleted' => true]);
    }
}
