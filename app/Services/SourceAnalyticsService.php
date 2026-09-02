<?php

declare(strict_types=1);

namespace App\Services;

use Modules\Leads\Enums\LeadSource;
use Modules\Leads\Models\Lead;
use App\Domain\Order\Models\Order;
use App\Models\LeadCycle;
use Illuminate\Support\Carbon;

class SourceAnalyticsService
{
    private const COST_PER_SOURCE = [
        'REFERRAL' => 5.00,
        'DELIVERED_WAYBILL' => 3.00,
        'WALK_IN' => 2.00,
        'PHONE' => 8.00,
        'TELESALES_IMPORT' => 12.00,
        'WEB' => 15.00,
        'SHOP' => 10.00,
        'FACEBOOK' => 20.00,
        'MANUAL' => 5.00,
        'XLSX_IMPORT' => 8.00,
        'WAYBILL' => 3.00,
    ];

    private const DEFAULT_CPA = 10.00;

    public function getAnalytics(?Carbon $from = null, ?Carbon $to = null): array
    {
        $from = $from ?? now()->subDays(30);
        $to = $to ?? now();
        $sources = $this->computePerSource($from, $to);

        return [
            'sources' => $sources,
            'summary' => $this->computeSummary($sources),
            'trend' => $this->computeTrend($from, $to),
            'top_sources' => $this->computeTopSources($sources),
        ];
    }

    private function srcVal(mixed $s): string
    {
        return $s instanceof LeadSource ? $s->value : (string) $s;
    }

    private function computePerSource(Carbon $from, Carbon $to): array
    {
        $leadStats = Lead::selectRaw('source, COUNT(*) as total_leads, COUNT(CASE WHEN assigned_to IS NOT NULL THEN 1 END) as assigned, COUNT(CASE WHEN status = \'SALE\' THEN 1 END) as converted')
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('source')
            ->get()
            ->keyBy(fn ($r) => $this->srcVal($r->source));

        $orderStats = Order::selectRaw('leads.source as source, COUNT(orders.id) as order_count, COALESCE(SUM(orders.total_amount), 0) as total_revenue, COALESCE(SUM(orders.shipping_cost), 0) as total_shipping')
            ->join('leads', 'orders.lead_id', '=', 'leads.id')
            ->whereBetween('orders.created_at', [$from, $to])
            ->groupBy('leads.source')
            ->get()
            ->keyBy(fn ($r) => $this->srcVal($r->source));

        $htStats = LeadCycle::selectRaw('leads.source as source, AVG(EXTRACT(EPOCH FROM (lead_cycles.closed_at - lead_cycles.opened_at)) / 3600) as avg_handle_hrs')
            ->join('leads', 'lead_cycles.lead_id', '=', 'leads.id')
            ->where('lead_cycles.outcome', 'ORDERED')
            ->whereNotNull('lead_cycles.opened_at')
            ->whereNotNull('lead_cycles.closed_at')
            ->whereBetween('lead_cycles.closed_at', [$from, $to])
            ->groupBy('leads.source')
            ->get()
            ->keyBy(fn ($r) => $this->srcVal($r->source));

        $result = [];
        foreach (LeadSource::cases() as $enum) {
            $val = $enum->value;
            $stats = $leadStats->get($val);
            $totalLeads = (int) ($stats?->total_leads ?? 0);
            if ($totalLeads === 0) {
                continue;
            }
            $orders = $orderStats->get($val);
            $ht = $htStats->get($val);
            $converted = (int) ($stats?->converted ?? 0);
            $revenue = (float) ($orders?->total_revenue ?? 0);
            $shipping = (float) ($orders?->total_shipping ?? 0);
            $orderCount = (int) ($orders?->order_count ?? 0);
            $cost = ($totalLeads * (self::COST_PER_SOURCE[$val] ?? self::DEFAULT_CPA)) + $shipping;
            $result[] = [
                'source' => $val,
                'label' => $enum->label(),
                'total_leads' => $totalLeads,
                'assigned' => (int) ($stats?->assigned ?? 0),
                'converted' => $converted,
                'conversion_rate' => round($converted / $totalLeads * 100, 1),
                'total_revenue' => round($revenue, 2),
                'total_cost' => round($cost, 2),
                'cpa' => round($converted > 0 ? $cost / $converted : 0.0, 2),
                'roi' => round($cost > 0 ? (($revenue - $cost) / $cost) * 100 : 0.0, 1),
                'avg_handle_time_hrs' => $ht ? round((float) $ht->avg_handle_hrs, 2) : 0.0,
                'avg_order_value' => $orderCount > 0 ? round($revenue / $orderCount, 2) : 0.0,
            ];
        }
        usort($result, fn ($a, $b) => $b['total_leads'] <=> $a['total_leads']);

        return $result;
    }

    private function computeSummary(array $sources): array
    {
        $tl = array_sum(array_column($sources, 'total_leads'));
        $tc = array_sum(array_column($sources, 'converted'));
        $tr = array_sum(array_column($sources, 'total_revenue'));
        $tCost = array_sum(array_column($sources, 'total_cost'));

        return [
            'total_leads' => $tl,
            'total_converted' => $tc,
            'overall_conversion_rate' => $tl > 0 ? round($tc / $tl * 100, 1) : 0.0,
            'total_revenue' => round($tr, 2),
            'total_cost' => round($tCost, 2),
            'blended_cpa' => $tc > 0 ? round($tCost / $tc, 2) : 0.0,
            'blended_roi' => $tCost > 0 ? round((($tr - $tCost) / $tCost) * 100, 1) : 0.0,
        ];
    }

    private function computeTrend(Carbon $from, Carbon $to): array
    {
        return Lead::selectRaw('DATE(created_at) as date, source, COUNT(*) as leads, COUNT(CASE WHEN status = \'SALE\' THEN 1 END) as conversions')
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('date', 'source')
            ->orderBy('date')
            ->get()
            ->map(fn ($r) => [
                'date' => $r->date,
                'source' => $this->srcVal($r->source),
                'leads' => (int) $r->leads,
                'conversions' => (int) $r->conversions,
            ])
            ->toArray();
    }

    private function computeTopSources(array $sources): array
    {
        $top = $sources;
        usort($top, fn ($a, $b) => $b['conversion_rate'] <=> $a['conversion_rate']);

        return array_slice(
            array_map(fn ($s) => [
                'source' => $s['source'],
                'label' => $s['label'],
                'conversion_rate' => $s['conversion_rate'],
                'revenue' => $s['total_revenue'],
            ], $top),
            0,
            5,
        );
    }
}
