<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Waybill\Services\ReturnWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ReturnWorkflowController extends Controller
{
    public function index(Request $request): \Inertia\Response
    {
        $service = app(ReturnWorkflowService::class);
        $data = $service->getDashboardData($request->only(['from', 'to', 'condition']));

        return Inertia::render('Waybills/ReturnWorkflow', $data);
    }

    public function apiDashboard(Request $request): JsonResponse
    {
        $service = app(ReturnWorkflowService::class);
        $data = $service->getDashboardData($request->only(['from', 'to', 'condition']));

        return response()->json($data);
    }

    public function scan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'waybill_number' => 'required|string|max:60',
            'condition'      => 'sometimes|in:GOOD,DAMAGED',
            'notes'          => 'nullable|string|max:1000',
        ]);

        $service = app(ReturnWorkflowService::class);
        $result = $service->scanAndProcess(
            $validated['waybill_number'],
            $request->user()->id,
            $validated['condition'] ?? 'GOOD',
            $validated['notes'] ?? null,
        );

        return response()->json($result);
    }

    public function batchScan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'waybill_numbers' => 'required|array|max:50',
            'waybill_numbers.*' => 'string|max:60',
            'condition'      => 'sometimes|in:GOOD,DAMAGED',
            'notes'          => 'nullable|string|max:1000',
        ]);

        $service = app(ReturnWorkflowService::class);
        $result = $service->batchScan(
            $validated['waybill_numbers'],
            $request->user()->id,
            $validated['condition'] ?? 'GOOD',
            $validated['notes'] ?? null,
        );

        return response()->json($result);
    }
}
