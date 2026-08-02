<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Waybill\Services\CourierAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CourierAnalyticsController extends Controller
{
    public function index(Request $request): Response
    {
        $service = app(CourierAnalyticsService::class);
        $data = $service->getDashboardData($request->only(['from', 'to', 'courier']));

        return Inertia::render('Waybills/CourierAnalytics', $data);
    }

    public function api(Request $request): JsonResponse
    {
        $service = app(CourierAnalyticsService::class);
        $data = $service->getDashboardData($request->only(['from', 'to', 'courier']));

        return response()->json($data);
    }

    public function export(Request $request): StreamedResponse
    {
        $from = $request->input('from', now()->subDays(30)->toDateString());
        $to = $request->input('to', now()->toDateString());

        $service = app(CourierAnalyticsService::class);

        return $service->exportCsv($from, $to);
    }
}
