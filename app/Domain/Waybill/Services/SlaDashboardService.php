<?php

declare(strict_types=1);

namespace App\Domain\Waybill\Services;

use App\Domain\Waybill\Enums\WaybillStatus;
use App\Domain\Waybill\Models\Waybill;
use App\Models\SiteSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class SlaDashboardService
{
    public function getDashboardData(array $filters = []): array
    {
        $slaDays = (int) SiteSetting::get('sla_return_days', '1');
        $manilaNow = now()->setTimezone('Asia/Manila');
        $slaCutoff = $manilaNow->copy()->startOfDay()->subDays($slaDays - 1)->utc();
        $courierFilter = $filters['courier'] ?? null;

        return [
            'summary' => $this->buildSummary($slaCutoff, $courierFilter),
            'by_courier' => $this->buildByCourier($slaCutoff, $courierFilter),
            'aging_buckets' => $this->buildAgingBuckets($slaCutoff, $courierFilter),
            'trend' => $this->buildTrend($slaDays, $courierFilter),
            'recent_breaches' => $this->buildRecentBreaches($slaCutoff, $courierFilter, 20),
            'settings' => $this->getSettings(),
            'filters' => [
                'from' => $filters['from'] ?? $manilaNow->copy()->subDays(30)->toDateString(),
                'to' => $filters['to'] ?? $manilaNow->copy()->toDateString(),
                'courier' => $courierFilter,
            ],
        ];
    }

    private function baseBreachesQuery(Carbon $slaCutoff, ?string $courier): Builder
    {
        return Waybill::where('status', WaybillStatus::RETURNED->value)
            ->where('returned_at', '<', $slaCutoff)
            ->whereDoesntHave('returnReceipt')
            ->when($courier, fn ($q, $v) => $q->where('courier_provider', $v));
    }

    private function buildSummary(Carbon $slaCutoff, ?string $courier): array
    {
        $q = $this->baseBreachesQuery($slaCutoff, $courier);
        $totalBreaches = (clone $q)->count();
        $totalCodAtRisk = (float) (clone $q)->sum('cod_amount');
        $avgDaysOverdue = (float) (clone $q)
            ->selectRaw('AVG(EXTRACT(EPOCH FROM (NOW() - returned_at)) / 86400) as avg_days')
            ->value('avg_days');

        $resolvedInSla = Waybill::where('status', WaybillStatus::RETURNED->value)
            ->where('returned_at', '<', $slaCutoff)
            ->whereHas('returnReceipt')
            ->when($courier, fn ($q, $v) => $q->where('courier_provider', $v))
            ->count();

        $totalReturned = Waybill::where('status', WaybillStatus::RETURNED->value)
            ->where('returned_at', '<', $slaCutoff)
            ->when($courier, fn ($q, $v) => $q->where('courier_provider', $v))
            ->count();

        $complianceRate = $totalReturned > 0
            ? round(($resolvedInSla / $totalReturned) * 100, 1) : 100.0;

        $criticalCount = (clone $q)->where('returned_at', '<', now()->subDays(7)->utc())->count();
        $claimsFiled = (clone $q)->whereHas('claims')->count();

        return [
            'total_breaches' => $totalBreaches,
            'cod_at_risk' => round($totalCodAtRisk, 2),
            'avg_days_overdue' => round($avgDaysOverdue, 1),
            'compliance_rate' => $complianceRate,
            'resolved_in_sla' => $resolvedInSla,
            'total_returned' => $totalReturned,
            'critical_count' => $criticalCount,
            'claims_filed' => $claimsFiled,
            'claims_pending' => $totalBreaches - $claimsFiled,
        ];
    }

    private function buildByCourier(Carbon $slaCutoff, ?string $courier): array
    {
        $rows = $this->baseBreachesQuery($slaCutoff, $courier)
            ->selectRaw("
                courier_provider,
                COUNT(*) as breach_count,
                COALESCE(SUM(cod_amount), 0) as cod_at_risk,
                AVG(EXTRACT(EPOCH FROM (NOW() - returned_at)) / 86400) as avg_days_overdue,
                COUNT(*) FILTER (WHERE returned_at < NOW() - INTERVAL '7 days') as critical_count
            ")
            ->groupBy('courier_provider')
            ->orderByDesc('breach_count')
            ->get();

        return $rows->map(fn ($r) => [
            'courier' => $r->courier_provider ?? 'Unknown',
            'breach_count' => (int) $r->breach_count,
            'cod_at_risk' => round((float) $r->cod_at_risk, 2),
            'avg_days_overdue' => round((float) $r->avg_days_overdue, 1),
            'critical_count' => (int) $r->critical_count,
        ])->toArray();
    }

    private function buildAgingBuckets(Carbon $slaCutoff, ?string $courier): array
    {
        $buckets = [
            ['label' => '1-2 days', 'min' => 1, 'max' => 2],
            ['label' => '3-7 days', 'min' => 3, 'max' => 7],
            ['label' => '8-14 days', 'min' => 8, 'max' => 14],
            ['label' => '15-30 days', 'min' => 15, 'max' => 30],
            ['label' => '30+ days', 'min' => 31, 'max' => 9999],
        ];

        $result = [];
        foreach ($buckets as $b) {
            $q = $this->baseBreachesQuery($slaCutoff, $courier)
                ->where('returned_at', '>=', now()->subDays($b['max'])->utc())
                ->where('returned_at', '<', now()->subDays($b['min'] - 1)->utc());

            $result[] = [
                'label' => $b['label'],
                'count' => (clone $q)->count(),
                'cod_value' => round((float) (clone $q)->sum('cod_amount'), 2),
            ];
        }

        return $result;
    }

    private function buildTrend(int $slaDays, ?string $courier): array
    {
        $manilaNow = now()->setTimezone('Asia/Manila');
        $trend = [];

        for ($i = 29; $i >= 0; $i--) {
            $date = $manilaNow->copy()->subDays($i);
            $dayStart = $date->copy()->startOfDay()->utc();
            $dayEnd = $date->copy()->endOfDay()->utc();

            $newBreaches = Waybill::where('status', WaybillStatus::RETURNED->value)
                ->whereBetween('returned_at', [$dayStart, $dayEnd])
                ->whereDoesntHave('returnReceipt')
                ->when($courier, fn ($q, $v) => $q->where('courier_provider', $v))
                ->count();

            $resolved = Waybill::where('status', WaybillStatus::RETURNED->value)
                ->whereHas('returnReceipt', fn ($q) => $q->whereBetween('created_at', [$dayStart, $dayEnd]))
                ->when($courier, fn ($q, $v) => $q->where('courier_provider', $v))
                ->count();

            $trend[] = [
                'date' => $date->toDateString(),
                'new_breaches' => $newBreaches,
                'resolved' => $resolved,
            ];
        }

        return $trend;
    }

    private function buildRecentBreaches(Carbon $slaCutoff, ?string $courier, int $limit): array
    {
        return $this->baseBreachesQuery($slaCutoff, $courier)
            ->with(['claims:id,waybill_id,status,claim_number', 'returnReceipt:id,waybill_id'])
            ->latest('returned_at')
            ->limit($limit)
            ->get()
            ->map(fn ($w) => [
                'id' => $w->id,
                'waybill_number' => $w->waybill_number,
                'courier' => $w->courier_provider ?? 'Unknown',
                'receiver_name' => $w->receiver_name ?? '—',
                'city' => $w->city ?? '—',
                'cod_amount' => (float) ($w->cod_amount ?? $w->amount ?? 0),
                'returned_at' => $w->returned_at?->toIso8601String(),
                'days_overdue' => $w->returned_at ? (int) $w->returned_at->diffInDays(now()) : 0,
                'has_claim' => $w->claims->isNotEmpty(),
                'claim_count' => $w->claims->count(),
            ])
            ->toArray();
    }

    public function getSettings(): array
    {
        return [
            'sla_return_days' => (int) SiteSetting::get('sla_return_days', '1'),
        ];
    }

    public function updateSettings(array $data): array
    {
        if (isset($data['sla_return_days'])) {
            SiteSetting::set('sla_return_days', (string) max(1, (int) $data['sla_return_days']));
        }

        return $this->getSettings();
    }
}
