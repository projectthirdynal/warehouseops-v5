<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Analytics\Services\SalesDashboardService;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

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
}
