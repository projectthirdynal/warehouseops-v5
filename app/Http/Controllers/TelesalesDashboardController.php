<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Lead\Models\Lead;
use App\Domain\Lead\Models\LeadPoolRequest;
use App\Domain\Order\Models\Order;
use App\Models\LeadCycle;
use App\Services\LeadEligibilityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class TelesalesDashboardController extends Controller
{
    public function __construct(
        private readonly LeadEligibilityService $eligibilityService,
    ) {
        $this->middleware(function ($request, $next) {
            if (! in_array(auth()->user()->role, ['superadmin', 'admin', 'supervisor', 'teamleader'], true)) {
                abort(403, 'Telesales dashboard access requires supervisor or admin role.');
            }

            return $next($request);
        });
    }

    public function index(Request $request): Response
    {
        $range = $request->string('range', '7d')->toString();
        $days = match ($range) {
            '30d' => 30,
            '90d' => 90,
            default => 7,
        };

        $end = CarbonImmutable::now()->endOfDay();
        $start = CarbonImmutable::now()->startOfDay()->subDays($days - 1);
        $previousEnd = $start->subSecond();
        $previousStart = $previousEnd->startOfDay()->subDays($days - 1);

        $assignedCurrent = $this->assignedCount($start, $end);
        $assignedPrevious = $this->assignedCount($previousStart, $previousEnd);
        $contactedCurrent = $this->contactedCount($start, $end);
        $contactedPrevious = $this->contactedCount($previousStart, $previousEnd);
        $ordersCurrent = $this->ordersCount($start, $end);
        $ordersPrevious = $this->ordersCount($previousStart, $previousEnd);
        $revenueCurrent = $this->revenue($start, $end);
        $revenuePrevious = $this->revenue($previousStart, $previousEnd);

        $conversionCurrent = $contactedCurrent > 0
            ? round(($ordersCurrent / $contactedCurrent) * 100, 2)
            : 0.0;
        $conversionPrevious = $contactedPrevious > 0
            ? round(($ordersPrevious / $contactedPrevious) * 100, 2)
            : 0.0;

        return Inertia::render('Telesales/Dashboard', [
            'range' => $range,
            'period' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'days' => $days,
            ],
            'kpis' => [
                'available' => [
                    'value' => $this->eligibilityService->countEligible(),
                    'trend' => null,
                ],
                'assigned' => [
                    'value' => $assignedCurrent,
                    'trend' => $this->percentDelta($assignedCurrent, $assignedPrevious),
                ],
                'contacted' => [
                    'value' => $contactedCurrent,
                    'trend' => $this->percentDelta($contactedCurrent, $contactedPrevious),
                ],
                'orders' => [
                    'value' => $ordersCurrent,
                    'trend' => $this->percentDelta($ordersCurrent, $ordersPrevious),
                ],
                'revenue' => [
                    'value' => round($revenueCurrent, 2),
                    'trend' => $this->percentDelta($revenueCurrent, $revenuePrevious),
                ],
                'conversion' => [
                    'value' => $conversionCurrent,
                    'trend' => $this->percentDelta($conversionCurrent, $conversionPrevious),
                ],
            ],
            'trend' => $this->trend($start, $end, $days),
            'brandBreakdown' => $this->brandBreakdown($start, $end),
            'regionBreakdown' => $this->regionBreakdown($start, $end),
            'recentPoolRequests' => $this->recentPoolRequests(),
            'topAgents' => $this->topAgents($start, $end),
        ]);
    }

    private function assignedCount(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return Lead::query()
            ->whereNotNull('assigned_at')
            ->whereBetween('assigned_at', [$start, $end])
            ->count();
    }

    private function contactedCount(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return LeadCycle::query()
            ->where('call_count', '>', 0)
            ->whereNotNull('last_call_at')
            ->whereBetween('last_call_at', [$start, $end])
            ->distinct('lead_id')
            ->count('lead_id');
    }

    private function ordersCount(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return Order::query()
            ->whereNotNull('lead_id')
            ->whereBetween('created_at', [$start, $end])
            ->count();
    }

    private function revenue(CarbonImmutable $start, CarbonImmutable $end): float
    {
        return (float) Order::query()
            ->whereNotNull('lead_id')
            ->whereBetween('created_at', [$start, $end])
            ->sum('total_amount');
    }

    /**
     * @return array<int, array{label:string,assigned:int,contacted:int,orders:int}>
     */
    private function trend(CarbonImmutable $start, CarbonImmutable $end, int $days): array
    {
        $assignedRows = Lead::query()
            ->whereNotNull('assigned_at')
            ->whereBetween('assigned_at', [$start, $end])
            ->selectRaw('DATE(assigned_at) as day, COUNT(*) as total')
            ->groupByRaw('DATE(assigned_at)')
            ->pluck('total', 'day');

        $contactedRows = LeadCycle::query()
            ->where('call_count', '>', 0)
            ->whereNotNull('last_call_at')
            ->whereBetween('last_call_at', [$start, $end])
            ->selectRaw('DATE(last_call_at) as day, COUNT(DISTINCT lead_id) as total')
            ->groupByRaw('DATE(last_call_at)')
            ->pluck('total', 'day');

        $orderRows = Order::query()
            ->whereNotNull('lead_id')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupByRaw('DATE(created_at)')
            ->pluck('total', 'day');

        $result = [];
        $cursor = $start->startOfDay();

        while ($cursor->lte($end)) {
            $key = $cursor->toDateString();
            $result[] = [
                'label' => $days > 30 ? $cursor->format('M j') : $cursor->format('M j'),
                'assigned' => (int) ($assignedRows[$key] ?? 0),
                'contacted' => (int) ($contactedRows[$key] ?? 0),
                'orders' => (int) ($orderRows[$key] ?? 0),
            ];

            $cursor = $cursor->addDay();
        }

        return $result;
    }

    /**
     * @return array<int, array{label:string,value:int}>
     */
    private function brandBreakdown(CarbonImmutable $start, CarbonImmutable $end): array
    {
        return DB::table('leads')
            ->whereNotNull('assigned_at')
            ->whereBetween('assigned_at', [$start, $end])
            ->selectRaw("COALESCE(NULLIF(product_brand, ''), NULLIF(product_name, ''), 'Unspecified') as label")
            ->selectRaw('COUNT(*) as value')
            ->groupByRaw("COALESCE(NULLIF(product_brand, ''), NULLIF(product_name, ''), 'Unspecified')")
            ->orderByDesc('value')
            ->limit(6)
            ->get()
            ->map(fn ($row) => [
                'label' => (string) $row->label,
                'value' => (int) $row->value,
            ])
            ->all();
    }

    /**
     * @return array<int, array{label:string,value:int}>
     */
    private function regionBreakdown(CarbonImmutable $start, CarbonImmutable $end): array
    {
        return DB::table('leads')
            ->leftJoin('address_mappings', 'leads.address_mapping_id', '=', 'address_mappings.id')
            ->whereNotNull('leads.assigned_at')
            ->whereBetween('leads.assigned_at', [$start, $end])
            ->selectRaw("COALESCE(NULLIF(address_mappings.business_region, ''), 'Unmapped') as label")
            ->selectRaw('COUNT(*) as value')
            ->groupByRaw("COALESCE(NULLIF(address_mappings.business_region, ''), 'Unmapped')")
            ->orderByDesc('value')
            ->limit(6)
            ->get()
            ->map(fn ($row) => [
                'label' => (string) $row->label,
                'value' => (int) $row->value,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentPoolRequests(): array
    {
        return LeadPoolRequest::query()
            ->with('requestedBy:id,name')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn (LeadPoolRequest $request) => [
                'id' => $request->id,
                'request_number' => $request->request_number,
                'brand_name' => $request->brand_name,
                'region' => $request->business_region ?: ($request->province ?: 'All Regions'),
                'requested_quantity' => $request->requested_quantity,
                'status' => $request->status->value,
                'requested_by' => $request->requestedBy?->name ?? 'Unknown',
                'created_at' => $request->created_at?->toISOString(),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function topAgents(CarbonImmutable $start, CarbonImmutable $end): array
    {
        return DB::table('lead_cycles')
            ->join('users', 'lead_cycles.assigned_agent_id', '=', 'users.id')
            ->whereBetween('lead_cycles.opened_at', [$start, $end])
            ->groupBy('users.id', 'users.name')
            ->select([
                'users.id',
                'users.name',
            ])
            ->selectRaw('COUNT(DISTINCT lead_cycles.lead_id) as assigned')
            ->selectRaw('COUNT(DISTINCT CASE WHEN lead_cycles.call_count > 0 THEN lead_cycles.lead_id END) as contacted')
            ->selectRaw("SUM(CASE WHEN lead_cycles.outcome = 'ORDERED' THEN 1 ELSE 0 END) as orders")
            ->orderByDesc('orders')
            ->orderByDesc('contacted')
            ->limit(5)
            ->get()
            ->map(function ($row) {
                $assigned = (int) $row->assigned;
                $contacted = (int) $row->contacted;
                $orders = (int) $row->orders;

                return [
                    'id' => (int) $row->id,
                    'name' => (string) $row->name,
                    'contact_rate' => $assigned > 0 ? round(($contacted / $assigned) * 100, 1) : 0,
                    'orders' => $orders,
                    'conversion' => $contacted > 0 ? round(($orders / $contacted) * 100, 1) : 0,
                ];
            })
            ->all();
    }

    private function percentDelta(float|int $current, float|int $previous): ?float
    {
        if ((float) $previous === 0.0) {
            return (float) $current === 0.0 ? 0.0 : null;
        }

        return round((((float) $current - (float) $previous) / abs((float) $previous)) * 100, 1);
    }
}
