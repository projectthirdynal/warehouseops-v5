<?php

declare(strict_types=1);

namespace Modules\Shop\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Orders\Enums\OrderStatus;

class ShopReportsEnhancementService
{
    /**
     * Conversion funnel: Conversations → Assigned → Order Created → Confirmed → Delivered.
     *
     * @return array{
     *     stages: array<int, array{stage: string, label: string, count: int, percentage: float}>,
     *     drop_off: array<int, array{from: string, to: string, lost: int, rate: float}>,
     * }
     */
    public function funnel(array $filters): array
    {
        if (! Schema::hasTable('conversations')) {
            return ['stages' => [], 'drop_off' => []];
        }

        $convQuery = DB::table('conversations')->whereNull('merged_into_id');
        $this->applyConversationFilters($convQuery, $filters);

        $totalConversations = (clone $convQuery)->count();
        $assigned = (clone $convQuery)->whereNotNull('assigned_agent_id')->count();
        $resolved = (clone $convQuery)->where('status', 'resolved')->count();

        $orderQuery = DB::table('orders')
            ->whereIn('source_channel', ['manual_shop', 'facebook_shop']);
        $this->applyOrderFilters($orderQuery, $filters);

        $ordersCreated = (clone $orderQuery)->count();
        $confirmed = (clone $orderQuery)
            ->whereIn('status', [OrderStatus::CONFIRMED->value, OrderStatus::QA_APPROVED->value])
            ->count();
        $delivered = (clone $orderQuery)
            ->where('status', OrderStatus::DELIVERED->value)
            ->count();

        $stages = [
            ['stage' => 'conversations', 'label' => 'Conversations', 'count' => $totalConversations],
            ['stage' => 'assigned', 'label' => 'Assigned', 'count' => $assigned],
            ['stage' => 'resolved', 'label' => 'Resolved', 'count' => $resolved],
            ['stage' => 'orders', 'label' => 'Orders Created', 'count' => $ordersCreated],
            ['stage' => 'confirmed', 'label' => 'Confirmed', 'count' => $confirmed],
            ['stage' => 'delivered', 'label' => 'Delivered', 'count' => $delivered],
        ];

        $maxCount = max(1, $totalConversations);

        foreach ($stages as &$stage) {
            $stage['percentage'] = round(($stage['count'] / $maxCount) * 100, 1);
        }
        unset($stage);

        $dropOff = [];
        for ($i = 0; $i < count($stages) - 1; $i++) {
            $lost = $stages[$i]['count'] - $stages[$i + 1]['count'];
            $rate = $stages[$i]['count'] > 0
                ? round(($lost / $stages[$i]['count']) * 100, 1)
                : 0.0;
            $dropOff[] = [
                'from' => $stages[$i]['label'],
                'to' => $stages[$i + 1]['label'],
                'lost' => max(0, $lost),
                'rate' => $rate,
            ];
        }

        return [
            'stages' => $stages,
            'drop_off' => $dropOff,
        ];
    }

    /**
     * Response time analytics: first response, resolution time, distribution.
     *
     * @return array{
     *     avg_first_response_seconds: int|null,
     *     median_first_response_seconds: int|null,
     *     avg_resolution_seconds: int|null,
     *     median_resolution_seconds: int|null,
     *     response_distribution: array<int, array{bucket: string, count: int}>,
     *     resolution_distribution: array<int, array{bucket: string, count: int}>,
     *     by_agent: array<int, array{agent_id: int|null, agent_name: string, avg_response_seconds: int|null, avg_resolution_seconds: int|null, conversations: int}>,
     * }
     */
    public function responseTime(array $filters): array
    {
        if (! Schema::hasTable('conversations')) {
            return [
                'avg_first_response_seconds' => null,
                'median_first_response_seconds' => null,
                'avg_resolution_seconds' => null,
                'median_resolution_seconds' => null,
                'response_distribution' => [],
                'resolution_distribution' => [],
                'by_agent' => [],
            ];
        }

        $query = DB::table('conversations')
            ->whereNull('merged_into_id')
            ->whereNotNull('first_response_time_seconds');

        $this->applyConversationFilters($query, $filters);

        $responseTimes = (clone $query)->pluck('first_response_time_seconds')->toArray();
        $resolutionTimes = (clone $query)
            ->whereNotNull('resolution_time_seconds')
            ->pluck('resolution_time_seconds')
            ->toArray();

        $avgResponse = count($responseTimes) > 0 ? (int) round(array_sum($responseTimes) / count($responseTimes)) : null;
        $medianResponse = count($responseTimes) > 0 ? (int) round($this->median($responseTimes)) : null;

        $avgResolution = count($resolutionTimes) > 0 ? (int) round(array_sum($resolutionTimes) / count($resolutionTimes)) : null;
        $medianResolution = count($resolutionTimes) > 0 ? (int) round($this->median($resolutionTimes)) : null;

        $responseBuckets = [
            '< 1 min' => [0, 60],
            '1-5 min' => [60, 300],
            '5-15 min' => [300, 900],
            '15-30 min' => [900, 1800],
            '30-60 min' => [1800, 3600],
            '1-2 hrs' => [3600, 7200],
            '> 2 hrs' => [7200, PHP_INT_MAX],
        ];

        $responseDistribution = [];
        foreach ($responseBuckets as $label => [$min, $max]) {
            $count = count(array_filter($responseTimes, fn ($t) => $t >= $min && $t < $max));
            $responseDistribution[] = ['bucket' => $label, 'count' => $count];
        }

        $resolutionBuckets = [
            '< 15 min' => [0, 900],
            '15-30 min' => [900, 1800],
            '30-60 min' => [1800, 3600],
            '1-2 hrs' => [3600, 7200],
            '2-4 hrs' => [7200, 14400],
            '4-8 hrs' => [14400, 28800],
            '> 8 hrs' => [28800, PHP_INT_MAX],
        ];

        $resolutionDistribution = [];
        foreach ($resolutionBuckets as $label => [$min, $max]) {
            $count = count(array_filter($resolutionTimes, fn ($t) => $t >= $min && $t < $max));
            $resolutionDistribution[] = ['bucket' => $label, 'count' => $count];
        }

        $byAgent = DB::table('conversations')
            ->whereNull('merged_into_id')
            ->whereNotNull('first_response_time_seconds')
            ->leftJoin('users', 'conversations.assigned_agent_id', '=', 'users.id')
            ->select(
                'conversations.assigned_agent_id',
                'users.name as agent_name',
                DB::raw('COUNT(*) as conversations'),
                DB::raw('AVG(conversations.first_response_time_seconds) as avg_response_seconds'),
                DB::raw('AVG(conversations.resolution_time_seconds) as avg_resolution_seconds')
            )
            ->groupBy('conversations.assigned_agent_id', 'users.name')
            ->orderByDesc('conversations')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'agent_id' => $row->assigned_agent_id,
                'agent_name' => $row->agent_name ?? 'Unassigned',
                'avg_response_seconds' => $row->avg_response_seconds ? (int) round($row->avg_response_seconds) : null,
                'avg_resolution_seconds' => $row->avg_resolution_seconds ? (int) round($row->avg_resolution_seconds) : null,
                'conversations' => (int) $row->conversations,
            ])
            ->toArray();

        return [
            'avg_first_response_seconds' => $avgResponse,
            'median_first_response_seconds' => $medianResponse,
            'avg_resolution_seconds' => $avgResolution,
            'median_resolution_seconds' => $medianResolution,
            'response_distribution' => $responseDistribution,
            'resolution_distribution' => $resolutionDistribution,
            'by_agent' => $byAgent,
        ];
    }

    /**
     * Peak hours: message volume by hour of day and day of week.
     *
     * @return array{
     *     hourly: array<int, array{hour: int, count: int}>,
     *     by_day: array<int, array{day: int, day_name: string, count: int}>,
     *     heatmap: array<int, array{day: int, hour: int, count: int}>,
     *     peak_hours: array<int, array{hour: int, count: int}>,
     *     total_messages: int,
     * }
     */
    public function peakHours(array $filters): array
    {
        if (! Schema::hasTable('messages')) {
            return [
                'hourly' => [],
                'by_day' => [],
                'heatmap' => [],
                'peak_hours' => [],
                'total_messages' => 0,
            ];
        }

        $query = DB::table('messages')
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->whereNull('conversations.merged_into_id')
            ->where('messages.direction', 'inbound');

        $this->applyConversationFilters($query, $filters, 'conversations');

        $dateFrom = $filters['date_from'] ?? today()->subDays(29)->toDateString();
        $dateTo = $filters['date_to'] ?? today()->toDateString();

        $query->whereDate('messages.created_at', '>=', $dateFrom)
            ->whereDate('messages.created_at', '<=', $dateTo);

        $hourExpr = DB::connection()->getDriverName() === 'sqlite'
            ? "CAST(strftime('%H', messages.created_at) AS INTEGER)"
            : 'EXTRACT(HOUR FROM messages.created_at)';

        $hourly = (clone $query)
            ->selectRaw($hourExpr.' as hour, COUNT(*) as count')
            ->groupByRaw($hourExpr)
            ->orderByRaw('hour')
            ->get()
            ->keyBy('hour')
            ->toArray();

        $hourlyResult = [];
        for ($h = 0; $h < 24; $h++) {
            $hourlyResult[] = [
                'hour' => $h,
                'count' => (int) ($hourly[$h]->count ?? 0),
            ];
        }

        $dayExpr = DB::connection()->getDriverName() === 'sqlite'
            ? "CAST(strftime('%w', messages.created_at) AS INTEGER)"
            : 'EXTRACT(DOW FROM messages.created_at)';

        $byDay = (clone $query)
            ->selectRaw($dayExpr.' as day, COUNT(*) as count')
            ->groupByRaw($dayExpr)
            ->orderByRaw('day')
            ->get()
            ->keyBy('day')
            ->toArray();

        $dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $byDayResult = [];
        for ($d = 0; $d < 7; $d++) {
            $byDayResult[] = [
                'day' => $d,
                'day_name' => $dayNames[$d],
                'count' => (int) ($byDay[$d]->count ?? 0),
            ];
        }

        $heatmapRaw = (clone $query)
            ->selectRaw($dayExpr.' as day, '.$hourExpr.' as hour, COUNT(*) as count')
            ->groupByRaw($dayExpr.', '.$hourExpr)
            ->get();

        $heatmap = [];
        foreach ($heatmapRaw as $cell) {
            $heatmap[] = [
                'day' => (int) $cell->day,
                'hour' => (int) $cell->hour,
                'count' => (int) $cell->count,
            ];
        }

        $sortedHourly = collect($hourlyResult)->sortByDesc('count')->take(5)->values()->toArray();

        $total = (clone $query)->count();

        return [
            'hourly' => $hourlyResult,
            'by_day' => $byDayResult,
            'heatmap' => $heatmap,
            'peak_hours' => $sortedHourly,
            'total_messages' => $total,
        ];
    }

    /**
     * Customer retention: new vs returning, repeat purchase rate, cohort-based.
     *
     * @return array{
     *     new_customers: int,
     *     returning_customers: int,
     *     repeat_purchase_rate: float,
     *     avg_orders_per_customer: float,
     *     distribution: array<int, array{order_count: int, customers: int}>,
     *     monthly: array<int, array{month: string, new: int, returning: int}>,
     * }
     */
    public function retention(array $filters): array
    {
        if (! Schema::hasTable('orders')) {
            return [
                'new_customers' => 0,
                'returning_customers' => 0,
                'repeat_purchase_rate' => 0.0,
                'avg_orders_per_customer' => 0.0,
                'distribution' => [],
                'monthly' => [],
            ];
        }

        $dateFrom = Carbon::parse($filters['date_from'] ?? today()->subDays(29)->toDateString())->startOfDay();
        $dateTo = Carbon::parse($filters['date_to'] ?? today()->toDateString())->endOfDay();

        $query = DB::table('orders')
            ->whereIn('source_channel', ['manual_shop', 'facebook_shop'])
            ->whereBetween('created_at', [$dateFrom, $dateTo]);

        if (! empty($filters['page_id'])) {
            $query->where('facebook_page_id', (int) $filters['page_id']);
        }

        if (! empty($filters['agent_id'])) {
            $query->where('assigned_agent_id', (int) $filters['agent_id']);
        }

        $customerOrders = (clone $query)
            ->select('customer_id', DB::raw('COUNT(*) as order_count'))
            ->whereNotNull('customer_id')
            ->groupBy('customer_id')
            ->get();

        $newCustomers = 0;
        $returningCustomers = 0;
        $distribution = [
            ['order_count' => 1, 'customers' => 0],
            ['order_count' => 2, 'customers' => 0],
            ['order_count' => 3, 'customers' => 0],
            ['order_count' => 4, 'customers' => 0],
            ['order_count' => 5, 'customers' => 0],
        ];

        foreach ($customerOrders as $co) {
            $count = (int) $co->order_count;

            if ($count === 1) {
                $newCustomers++;
                $distribution[0]['customers']++;
            } else {
                $returningCustomers++;
                $bucket = min($count, 5) - 1;
                $distribution[$bucket]['customers']++;
            }
        }

        $totalCustomers = $newCustomers + $returningCustomers;
        $repeatRate = $totalCustomers > 0
            ? round(($returningCustomers / $totalCustomers) * 100, 1)
            : 0.0;

        $avgOrders = $totalCustomers > 0
            ? round((clone $query)->count() / $totalCustomers, 1)
            : 0.0;

        // Monthly new vs returning (last 6 months)
        $monthly = [];
        for ($i = 5; $i >= 0; $i--) {
            $monthStart = Carbon::now()->subMonths($i)->startOfMonth();
            $monthEnd = Carbon::now()->subMonths($i)->endOfMonth();

            $monthQuery = DB::table('orders')
                ->whereIn('source_channel', ['manual_shop', 'facebook_shop'])
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->whereNotNull('customer_id');

            if (! empty($filters['page_id'])) {
                $monthQuery->where('facebook_page_id', (int) $filters['page_id']);
            }

            if (! empty($filters['agent_id'])) {
                $monthQuery->where('assigned_agent_id', (int) $filters['agent_id']);
            }

            $monthOrders = (clone $monthQuery)
                ->select('customer_id', DB::raw('COUNT(*) as cnt'))
                ->groupBy('customer_id')
                ->get();

            $customerIds = $monthOrders->pluck('customer_id')->all();

            $firstOrderDates = [];
            if (! empty($customerIds)) {
                $firstOrderDates = DB::table('orders')
                    ->whereIn('customer_id', $customerIds)
                    ->whereIn('source_channel', ['manual_shop', 'facebook_shop'])
                    ->select('customer_id', DB::raw('MIN(created_at) as first_order'))
                    ->groupBy('customer_id')
                    ->pluck('first_order', 'customer_id')
                    ->toArray();
            }

            $monthNew = 0;
            $monthReturning = 0;

            foreach ($monthOrders as $mo) {
                $firstOrderDate = $firstOrderDates[$mo->customer_id] ?? null;

                if ($firstOrderDate && Carbon::parse($firstOrderDate)->between($monthStart, $monthEnd)) {
                    $monthNew++;
                } else {
                    $monthReturning++;
                }
            }

            $monthly[] = [
                'month' => $monthStart->format('M Y'),
                'new' => $monthNew,
                'returning' => $monthReturning,
            ];
        }

        return [
            'new_customers' => $newCustomers,
            'returning_customers' => $returningCustomers,
            'repeat_purchase_rate' => $repeatRate,
            'avg_orders_per_customer' => $avgOrders,
            'distribution' => $distribution,
            'monthly' => $monthly,
        ];
    }

    private function median(array $values): float
    {
        sort($values);
        $count = count($values);
        if ($count === 0) {
            return 0.0;
        }
        $mid = (int) floor($count / 2);
        if ($count % 2 === 0) {
            return ($values[$mid - 1] + $values[$mid]) / 2;
        }

        return (float) $values[$mid];
    }

    private function applyConversationFilters($query, array $filters, string $table = 'conversations'): void
    {
        if (! empty($filters['date_from'])) {
            $query->whereDate("{$table}.created_at", '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate("{$table}.created_at", '<=', $filters['date_to']);
        }
        if (! empty($filters['page_id'])) {
            $query->where("{$table}.facebook_page_id", (int) $filters['page_id']);
        }
        if (! empty($filters['agent_id'])) {
            $query->where("{$table}.assigned_agent_id", (int) $filters['agent_id']);
        }
    }

    private function applyOrderFilters($query, array $filters): void
    {
        if (! empty($filters['date_from'])) {
            $query->whereDate('orders.created_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('orders.created_at', '<=', $filters['date_to']);
        }
        if (! empty($filters['page_id'])) {
            $query->where('orders.facebook_page_id', (int) $filters['page_id']);
        }
        if (! empty($filters['agent_id'])) {
            $query->where('orders.assigned_agent_id', (int) $filters['agent_id']);
        }
    }
}
