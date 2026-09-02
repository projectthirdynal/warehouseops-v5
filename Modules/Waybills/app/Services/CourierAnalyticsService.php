<?php

declare(strict_types=1);

namespace Modules\Waybills\Services;

use App\Models\Waybill;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Couriers\Models\CourierProvider;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CourierAnalyticsService
{
    public function getDashboardData(array $filters = []): array
    {
        $from = $filters['from'] ?? now()->subDays(30)->toDateString();
        $to = $filters['to'] ?? now()->toDateString();
        $courier = $filters['courier'] ?? null;

        $baseQuery = Waybill::query()
            ->whereNotNull('courier_provider')
            ->where('courier_provider', '!=', 'MANUAL')
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->when($courier, fn ($q) => $q->where('courier_provider', $courier));

        return [
            'overview' => $this->getOverview($baseQuery),
            'by_courier' => $this->getByCourier($from, $to),
            'trends' => $this->getTrends($from, $to, $courier),
            'status_dist' => $this->getStatusDistribution($baseQuery),
            'transit' => $this->getTransitTimes($baseQuery),
            'top_cities' => $this->getTopCities($baseQuery),
            'couriers' => $this->getActiveCouriers(),
            'filters' => ['from' => $from, 'to' => $to, 'courier' => $courier],
        ];
    }

    private function getOverview($baseQuery): array
    {
        $total = (clone $baseQuery)->count();
        $delivered = (clone $baseQuery)->where('status', 'DELIVERED')->count();
        $returned = (clone $baseQuery)->where('status', 'RETURNED')->count();
        $failed = (clone $baseQuery)->where('status', 'DELIVERY_FAILED')->count();
        $inTransit = (clone $baseQuery)->whereIn('status', ['DISPATCHED', 'PICKED_UP', 'IN_TRANSIT', 'ARRIVED_HUB', 'OUT_FOR_DELIVERY', 'RETURNING'])->count();
        $cancelled = (clone $baseQuery)->where('status', 'CANCELLED')->count();

        $onTime = (clone $baseQuery)
            ->where('status', 'DELIVERED')
            ->whereNotNull('delivered_at')
            ->whereNotNull('dispatched_at')
            ->get()
            ->filter(fn ($w) => $w->delivered_at->diffInDays($w->dispatched_at) <= 3)
            ->count();

        $avgTransitHours = (clone $baseQuery)
            ->where('status', 'DELIVERED')
            ->whereNotNull('delivered_at')
            ->whereNotNull('dispatched_at')
            ->get()
            ->avg(fn ($w) => $w->delivered_at->diffInHours($w->dispatched_at));

        $codCollected = (float) (clone $baseQuery)->where('status', 'DELIVERED')->sum('cod_amount');
        $codAtRisk = (float) (clone $baseQuery)->where('status', 'RETURNED')->sum('cod_amount');
        $shippingCost = (float) (clone $baseQuery)->sum('shipping_cost');

        return [
            'total' => $total,
            'delivered' => $delivered,
            'returned' => $returned,
            'failed' => $failed,
            'in_transit' => $inTransit,
            'cancelled' => $cancelled,
            'on_time' => $onTime,
            'on_time_rate' => $delivered > 0 ? round(($onTime / $delivered) * 100, 1) : 0,
            'delivery_rate' => $total > 0 ? round(($delivered / $total) * 100, 1) : 0,
            'return_rate' => $total > 0 ? round(($returned / $total) * 100, 1) : 0,
            'failure_rate' => $total > 0 ? round(($failed / $total) * 100, 1) : 0,
            'avg_transit_hrs' => $avgTransitHours ? round($avgTransitHours, 1) : null,
            'avg_transit_days' => $avgTransitHours ? round($avgTransitHours / 24, 1) : null,
            'cod_collected' => $codCollected,
            'cod_at_risk' => $codAtRisk,
            'shipping_cost' => $shippingCost,
        ];
    }

    private function getByCourier(string $from, string $to): array
    {
        $couriers = CourierProvider::where('is_active', true)->pluck('name', 'code')->toArray();
        $couriers['MANUAL'] = 'Manual';

        $rows = DB::table('waybills')
            ->select('courier_provider')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'DELIVERED' THEN 1 ELSE 0 END) as delivered")
            ->selectRaw("SUM(CASE WHEN status = 'RETURNED' THEN 1 ELSE 0 END) as returned")
            ->selectRaw("SUM(CASE WHEN status = 'DELIVERY_FAILED' THEN 1 ELSE 0 END) as failed")
            ->selectRaw("SUM(CASE WHEN status IN ('DISPATCHED','PICKED_UP','IN_TRANSIT','ARRIVED_HUB','OUT_FOR_DELIVERY','RETURNING') THEN 1 ELSE 0 END) as in_transit")
            ->selectRaw("SUM(CASE WHEN status = 'CANCELLED' THEN 1 ELSE 0 END) as cancelled")
            ->selectRaw("SUM(CASE WHEN status = 'DELIVERED' THEN cod_amount ELSE 0 END) as cod_collected")
            ->selectRaw("SUM(CASE WHEN status = 'RETURNED' THEN cod_amount ELSE 0 END) as cod_at_risk")
            ->selectRaw('COALESCE(SUM(shipping_cost), 0) as shipping_cost')
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->groupBy('courier_provider')
            ->get()
            ->map(function ($row) use ($couriers) {
                $total = (int) $row->total;
                $delivered = (int) $row->delivered;

                $avgTransit = Waybill::where('courier_provider', $row->courier_provider)
                    ->where('status', 'DELIVERED')
                    ->whereNotNull('delivered_at')
                    ->whereNotNull('dispatched_at')
                    ->whereDate('created_at', '>=', now()->subDays(90))
                    ->get()
                    ->avg(fn ($w) => $w->delivered_at->diffInHours($w->dispatched_at));

                $onTime = Waybill::where('courier_provider', $row->courier_provider)
                    ->where('status', 'DELIVERED')
                    ->whereNotNull('delivered_at')
                    ->whereNotNull('dispatched_at')
                    ->whereDate('created_at', '>=', now()->subDays(90))
                    ->get()
                    ->filter(fn ($w) => $w->delivered_at->diffInDays($w->dispatched_at) <= 3)
                    ->count();

                $onTimeDelivered = Waybill::where('courier_provider', $row->courier_provider)
                    ->where('status', 'DELIVERED')
                    ->whereNotNull('delivered_at')
                    ->whereNotNull('dispatched_at')
                    ->whereDate('created_at', '>=', now()->subDays(90))
                    ->count();

                return [
                    'courier_code' => $row->courier_provider,
                    'courier_name' => $couriers[$row->courier_provider] ?? $row->courier_provider,
                    'total' => $total,
                    'delivered' => $delivered,
                    'returned' => (int) $row->returned,
                    'failed' => (int) $row->failed,
                    'in_transit' => (int) $row->in_transit,
                    'cancelled' => (int) $row->cancelled,
                    'delivery_rate' => $total > 0 ? round(($delivered / $total) * 100, 1) : 0,
                    'return_rate' => $total > 0 ? round(((int) $row->returned / $total) * 100, 1) : 0,
                    'failure_rate' => $total > 0 ? round(((int) $row->failed / $total) * 100, 1) : 0,
                    'on_time_rate' => $onTimeDelivered > 0 ? round(($onTime / $onTimeDelivered) * 100, 1) : 0,
                    'avg_transit_hrs' => $avgTransit ? round($avgTransit, 1) : null,
                    'avg_transit_days' => $avgTransit ? round($avgTransit / 24, 1) : null,
                    'cod_collected' => (float) $row->cod_collected,
                    'cod_at_risk' => (float) $row->cod_at_risk,
                    'shipping_cost' => (float) $row->shipping_cost,
                ];
            })
            ->sortByDesc('total')
            ->values()
            ->toArray();

        return $rows;
    }

    private function getTrends(string $from, string $to, ?string $courier): array
    {
        $startDate = Carbon::parse($from);
        $endDate = Carbon::parse($to);
        $days = $startDate->diffInDays($endDate);

        $groupBy = $days > 90 ? 'month' : ($days > 14 ? 'week' : 'day');

        $query = DB::table('waybills')
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->whereNotNull('courier_provider')
            ->where('courier_provider', '!=', 'MANUAL')
            ->when($courier, fn ($q) => $q->where('courier_provider', $courier));

        if ($groupBy === 'day') {
            $rows = (clone $query)
                ->selectRaw('DATE(created_at) as period')
                ->selectRaw('COUNT(*) as total')
                ->selectRaw("SUM(CASE WHEN status = 'DELIVERED' THEN 1 ELSE 0 END) as delivered")
                ->selectRaw("SUM(CASE WHEN status = 'RETURNED' THEN 1 ELSE 0 END) as returned")
                ->selectRaw("SUM(CASE WHEN status = 'DELIVERY_FAILED' THEN 1 ELSE 0 END) as failed")
                ->groupByRaw('DATE(created_at)')
                ->orderByRaw('DATE(created_at)')
                ->get();
        } elseif ($groupBy === 'week') {
            $rows = (clone $query)
                ->selectRaw("DATE_TRUNC('week', created_at)::date as period")
                ->selectRaw('COUNT(*) as total')
                ->selectRaw("SUM(CASE WHEN status = 'DELIVERED' THEN 1 ELSE 0 END) as delivered")
                ->selectRaw("SUM(CASE WHEN status = 'RETURNED' THEN 1 ELSE 0 END) as returned")
                ->selectRaw("SUM(CASE WHEN status = 'DELIVERY_FAILED' THEN 1 ELSE 0 END) as failed")
                ->groupByRaw('period')
                ->orderByRaw('period')
                ->get();
        } else {
            $rows = (clone $query)
                ->selectRaw("TO_CHAR(created_at, 'YYYY-MM') as period")
                ->selectRaw('COUNT(*) as total')
                ->selectRaw("SUM(CASE WHEN status = 'DELIVERED' THEN 1 ELSE 0 END) as delivered")
                ->selectRaw("SUM(CASE WHEN status = 'RETURNED' THEN 1 ELSE 0 END) as returned")
                ->selectRaw("SUM(CASE WHEN status = 'DELIVERY_FAILED' THEN 1 ELSE 0 END) as failed")
                ->groupByRaw('period')
                ->orderByRaw('period')
                ->get();
        }

        return [
            'group_by' => $groupBy,
            'data' => $rows->map(fn ($r) => [
                'period' => $r->period,
                'total' => (int) $r->total,
                'delivered' => (int) $r->delivered,
                'returned' => (int) $r->returned,
                'failed' => (int) $r->failed,
                'delivery_rate' => (int) $r->total > 0 ? round(((int) $r->delivered / (int) $r->total) * 100, 1) : 0,
                'return_rate' => (int) $r->total > 0 ? round(((int) $r->returned / (int) $r->total) * 100, 1) : 0,
            ])->toArray(),
        ];
    }

    private function getStatusDistribution($baseQuery): array
    {
        return (clone $baseQuery)
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($r) => ['status' => $r->status, 'count' => (int) $r->count])
            ->toArray();
    }

    private function getTransitTimes($baseQuery): array
    {
        $waybills = (clone $baseQuery)
            ->where('status', 'DELIVERED')
            ->whereNotNull('delivered_at')
            ->whereNotNull('dispatched_at')
            ->get();

        $buckets = [
            '0-1 day' => 0,
            '1-2 days' => 0,
            '2-3 days' => 0,
            '3-5 days' => 0,
            '5-7 days' => 0,
            '7+ days' => 0,
        ];

        foreach ($waybills as $w) {
            $days = $w->delivered_at->diffInDays($w->dispatched_at);
            if ($days <= 1) {
                $buckets['0-1 day']++;
            } elseif ($days <= 2) {
                $buckets['1-2 days']++;
            } elseif ($days <= 3) {
                $buckets['2-3 days']++;
            } elseif ($days <= 5) {
                $buckets['3-5 days']++;
            } elseif ($days <= 7) {
                $buckets['5-7 days']++;
            } else {
                $buckets['7+ days']++;
            }
        }

        return array_map(fn ($k, $v) => ['bucket' => $k, 'count' => $v], array_keys($buckets), $buckets);
    }

    private function getTopCities($baseQuery): array
    {
        return (clone $baseQuery)
            ->select('city', DB::raw('COUNT(*) as total'))
            ->selectRaw("SUM(CASE WHEN status = 'DELIVERED' THEN 1 ELSE 0 END) as delivered")
            ->selectRaw("SUM(CASE WHEN status = 'RETURNED' THEN 1 ELSE 0 END) as returned")
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->groupBy('city')
            ->orderByDesc('total')
            ->limit(15)
            ->get()
            ->map(fn ($r) => [
                'city' => $r->city,
                'total' => (int) $r->total,
                'delivered' => (int) $r->delivered,
                'returned' => (int) $r->returned,
                'delivery_rate' => (int) $r->total > 0 ? round(((int) $r->delivered / (int) $r->total) * 100, 1) : 0,
                'return_rate' => (int) $r->total > 0 ? round(((int) $r->returned / (int) $r->total) * 100, 1) : 0,
            ])
            ->toArray();
    }

    private function getActiveCouriers(): array
    {
        return CourierProvider::where('is_active', true)
            ->select('code', 'name')
            ->get()
            ->map(fn ($c) => ['code' => $c->code, 'name' => $c->name])
            ->toArray();
    }

    public function exportCsv(string $from, string $to): StreamedResponse
    {
        $data = $this->getDashboardData(['from' => $from, 'to' => $to]);

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="courier-analytics-'.$from.'-to-'.$to.'.csv"',
        ];

        return response()->stream(function () use ($data) {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['Courier Analytics Report']);
            fputcsv($out, ['Period', $data['filters']['from'].' to '.$data['filters']['to']]);
            fputcsv($out, []);

            fputcsv($out, ['--- Overview ---']);
            $ov = $data['overview'];
            fputcsv($out, ['Metric', 'Value']);
            fputcsv($out, ['Total Shipments', $ov['total']]);
            fputcsv($out, ['Delivered', $ov['delivered']]);
            fputcsv($out, ['Returned', $ov['returned']]);
            fputcsv($out, ['Delivery Failed', $ov['failed']]);
            fputcsv($out, ['In Transit', $ov['in_transit']]);
            fputcsv($out, ['Cancelled', $ov['cancelled']]);
            fputcsv($out, ['On-Time Count', $ov['on_time']]);
            fputcsv($out, ['On-Time Rate (%)', $ov['on_time_rate']]);
            fputcsv($out, ['Delivery Rate (%)', $ov['delivery_rate']]);
            fputcsv($out, ['Return Rate (%)', $ov['return_rate']]);
            fputcsv($out, ['Failure Rate (%)', $ov['failure_rate']]);
            fputcsv($out, ['Avg Transit (hours)', $ov['avg_transit_hrs'] ?? 'N/A']);
            fputcsv($out, ['Avg Transit (days)', $ov['avg_transit_days'] ?? 'N/A']);
            fputcsv($out, ['COD Collected', number_format((float) $ov['cod_collected'], 2)]);
            fputcsv($out, ['COD at Risk', number_format((float) $ov['cod_at_risk'], 2)]);
            fputcsv($out, ['Shipping Cost', number_format((float) $ov['shipping_cost'], 2)]);
            fputcsv($out, []);

            fputcsv($out, ['--- Per Courier Breakdown ---']);
            fputcsv($out, ['Courier', 'Total', 'Delivered', 'Returned', 'Failed', 'In Transit', 'Cancelled', 'Delivery Rate (%)', 'Return Rate (%)', 'Failure Rate (%)', 'On-Time Rate (%)', 'Avg Transit (hrs)', 'Avg Transit (days)', 'COD Collected', 'COD at Risk', 'Shipping Cost']);
            foreach ($data['by_courier'] as $row) {
                fputcsv($out, [
                    $row['courier_name'], $row['total'], $row['delivered'], $row['returned'],
                    $row['failed'], $row['in_transit'], $row['cancelled'],
                    $row['delivery_rate'], $row['return_rate'], $row['failure_rate'],
                    $row['on_time_rate'], $row['avg_transit_hrs'] ?? 'N/A', $row['avg_transit_days'] ?? 'N/A',
                    number_format($row['cod_collected'], 2), number_format($row['cod_at_risk'], 2),
                    number_format($row['shipping_cost'], 2),
                ]);
            }
            fputcsv($out, []);

            fputcsv($out, ['--- Trends ('.$data['trends']['group_by'].') ---']);
            fputcsv($out, ['Period', 'Total', 'Delivered', 'Returned', 'Failed', 'Delivery Rate (%)', 'Return Rate (%)']);
            foreach ($data['trends']['data'] as $row) {
                fputcsv($out, [
                    $row['period'], $row['total'], $row['delivered'], $row['returned'],
                    $row['failed'], $row['delivery_rate'], $row['return_rate'],
                ]);
            }
            fputcsv($out, []);

            fputcsv($out, ['--- Top Cities ---']);
            fputcsv($out, ['City', 'Total', 'Delivered', 'Returned', 'Delivery Rate (%)', 'Return Rate (%)']);
            foreach ($data['top_cities'] as $row) {
                fputcsv($out, [
                    $row['city'], $row['total'], $row['delivered'], $row['returned'],
                    $row['delivery_rate'], $row['return_rate'],
                ]);
            }

            fclose($out);
        }, 200, $headers);
    }
}
