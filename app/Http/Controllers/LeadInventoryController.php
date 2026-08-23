<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use App\Services\LeadEligibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LeadInventoryController extends Controller
{
    public function __construct(
        private LeadEligibilityService $eligibilityService
    ) {
        $this->middleware(function ($request, $next) {
            if (! in_array(auth()->user()->role, ['superadmin', 'admin', 'supervisor', 'teamleader'])) {
                abort(403, 'Telesales inventory access requires supervisor or admin role.');
            }

            return $next($request);
        });
    }

    /**
     * Lead Inventory page — shows eligible lead counts by brand, region, and age band.
     */
    public function index(Request $request): Response
    {
        $geoFilters = $request->only(['business_region', 'province', 'city']);

        $breakdown = $this->eligibilityService->getInventoryBreakdown($geoFilters);
        $summary = $this->eligibilityService->getInventorySummary();
        $filterOptions = $this->eligibilityService->getFilterOptions();

        return Inertia::render('Telesales/LeadInventory/Index', [
            'breakdown' => $breakdown,
            'summary' => $summary,
            'filterOptions' => $filterOptions,
            'filters' => $geoFilters,
            'maxWaybillAgeDays' => (int) SystemSetting::get('telesales_max_waybill_age_days', 60),
        ]);
    }

    /**
     * API endpoint: count eligible leads for specific filters (used by the
     * "Available Leads" counter in the future Pool Request form).
     */
    public function count(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'brand' => ['nullable', 'string'],
            'product' => ['nullable', 'string'],
            'business_region' => ['nullable', 'string'],
            'province' => ['nullable', 'string'],
            'city' => ['nullable', 'string'],
            'age_from' => ['nullable', 'integer', 'min:0'],
            'age_to' => ['nullable', 'integer', 'min:1'],
            'source' => ['nullable', 'string'],
        ]);

        $count = $this->eligibilityService->countEligible($filters);

        return response()->json([
            'count' => $count,
            'filters' => $filters,
        ]);
    }
}
