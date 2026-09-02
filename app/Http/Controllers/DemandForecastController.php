<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Modules\Inventory\Services\DemandForecastService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DemandForecastController extends Controller
{
    public function __construct(
        private readonly DemandForecastService $forecastService,
    ) {}

    public function index()
    {
        $summary = $this->forecastService->getForecastSummaryList();

        return Inertia::render('Inventory/DemandForecasting', [
            'summary' => $summary,
        ]);
    }

    public function api(Request $request)
    {
        $limit = (int) $request->query('limit', 50);
        $historyDays = (int) $request->query('history_days', 60);

        return response()->json([
            'summary' => $this->forecastService->getForecastSummaryList($limit, $historyDays),
        ]);
    }

    public function apiProductDetail(Request $request, int $productId)
    {
        $forecastDays = (int) $request->query('forecast_days', 30);

        return response()->json(
            $this->forecastService->getProductForecastDetail($productId, $forecastDays)
        );
    }
}
