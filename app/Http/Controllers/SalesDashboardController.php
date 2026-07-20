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
        ]);
    }

    public function apiOrderCounts(): JsonResponse
    {
        return response()->json([
            'order_counts' => $this->service->orderCounts(),
        ]);
    }
}
