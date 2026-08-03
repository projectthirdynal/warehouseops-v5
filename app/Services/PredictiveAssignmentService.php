<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Lead\Enums\LeadSource;
use App\Domain\Lead\Models\Lead;
use App\Models\AgentProfile;
use App\Models\LeadCycle;
use App\Models\PredictiveModelData;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Leads & Distribution Engine — Predictive Assignment (ML-based best-agent prediction).
 *
 * Uses historical LeadCycle data to build a feature-based prediction model per agent.
 * The model computes a conversion probability for each (agent, lead) pair using:
 *   - Agent conversion rate (historical sales / total cycles)
 *   - Source affinity (how well the agent converts leads from this source)
 *   - Region affinity (how well the agent converts leads from this region)
 *   - Product affinity (how well the agent converts leads for this product)
 *   - Time-of-day score (agent's historical performance at the current hour)
 *   - Recency (time since last assignment — avoids overloading one agent)
 *   - Handle time (faster closers get priority for time-sensitive leads)
 *
 * The model is retrained via `predictive:retrain` command and cached in
 * `predictive_model_data` table so predictions are O(1) at assignment time.
 */
class PredictiveAssignmentService
{
    private const MODEL_VERSION = 'v1';

    private const W_CONVERSION = 0.35;

    private const W_SOURCE = 0.15;

    private const W_REGION = 0.10;

    private const W_PRODUCT = 0.10;

    private const W_TIME = 0.10;

    private const W_RECENCY = 0.10;

    private const W_HANDLE_TIME = 0.10;

    /**
     * Predict the best agent for a given lead from a pool of eligible agents.
     *
     * @param  Collection<int, AgentProfile>  $agents
     * @return array{agent_id: ?int, score: float, reason: string, factors: array<string, float>}
     */
    public function predict(Lead $lead, Collection $agents): array
    {
        if ($agents->isEmpty()) {
            return [
                'agent_id' => null,
                'score' => 0.0,
                'reason' => 'No eligible agents',
                'factors' => [],
            ];
        }

        $agentIds = $agents->pluck('user_id')->all();
        $modelData = PredictiveModelData::whereIn('agent_id', $agentIds)
            ->where('model_version', self::MODEL_VERSION)
            ->get()
            ->keyBy('agent_id');

        $nowHour = (int) now()->format('H');
        $leadSource = $lead->source instanceof LeadSource
            ? $lead->source->value
            : (string) $lead->source;
        $leadRegion = strtoupper($lead->state ?? '');
        $leadProduct = strtoupper($lead->product_name ?? '');

        $scored = $agents->map(function (AgentProfile $agent) use ($modelData, $leadSource, $leadRegion, $leadProduct, $nowHour) {
            $data = $modelData->get($agent->user_id);

            // Fallback to agent's static performance_score if no trained data
            if (!$data) {
                $fallbackScore = ($agent->performance_score ?? 50) / 100;

                return [
                    'agent_id' => $agent->user_id,
                    'score' => round($fallbackScore * 0.5, 4), // Discount untrained agents
                    'factors' => ['conversion' => $fallbackScore, 'trained' => 0.0],
                ];
            }

            $factors = $this->computeFactors($data, $leadSource, $leadRegion, $leadProduct, $nowHour, $agent);
            $score = $this->weightedScore($factors);

            return [
                'agent_id' => $agent->user_id,
                'score' => round($score, 4),
                'factors' => $factors,
            ];
        });

        $best = $scored->sortByDesc('score')->first();

        return [
            'agent_id' => $best['agent_id'] ?? null,
            'score' => $best['score'] ?? 0.0,
            'reason' => 'Predictive ML assignment (conversion probability: '.round(($best['score'] ?? 0) * 100, 1).'%)',
            'factors' => $best['factors'] ?? [],
        ];
    }

    /**
     * Retrain the predictive model from historical LeadCycle data.
     * Computes per-agent feature scores and persists them.
     *
     * @return array{agents_trained: int, total_cycles: int, total_sales: int}
     */
    public function retrain(): array
    {
        $agents = User::where('role', 'agent')->where('is_active', true)->pluck('id')->all();

        if (empty($agents)) {
            return ['agents_trained' => 0, 'total_cycles' => 0, 'total_sales' => 0];
        }

        $totalCycles = 0;
        $totalSales = 0;

        foreach ($agents as $agentId) {
            $data = $this->buildAgentModelData($agentId);
            $totalCycles += $data['total_cycles'];
            $totalSales += $data['total_sales'];

            PredictiveModelData::updateOrCreate(
                ['agent_id' => $agentId, 'model_version' => self::MODEL_VERSION],
                array_merge($data, ['trained_at' => now()])
            );
        }

        Log::info('PredictiveAssignmentService: retrained '.count($agents).' agents, '.$totalCycles.' cycles, '.$totalSales.' sales');

        return [
            'agents_trained' => count($agents),
            'total_cycles' => $totalCycles,
            'total_sales' => $totalSales,
        ];
    }

    /**
     * Build the feature vector for a single agent from historical data.
     *
     * @return array<string, mixed>
     */
    private function buildAgentModelData(int $agentId): array
    {
        $cycles = LeadCycle::where('assigned_agent_id', $agentId)
            ->whereNotNull('outcome')
            ->with('lead')
            ->get();

        $totalCycles = $cycles->count();
        $sales = $cycles->where('outcome', 'ORDERED')->count();
        $conversionRate = $totalCycles > 0 ? $sales / $totalCycles : 0.0;

        // Handle time: average hours from opened_at to closed_at for sold leads
        $soldCycles = $cycles->where('outcome', 'ORDERED')->filter(fn ($c) => $c->opened_at && $c->closed_at);
        $avgHandleTime = $soldCycles->isNotEmpty()
            ? $soldCycles->avg(fn ($c) => $c->opened_at->diffInHours($c->closed_at))
            : 0.0;

        // Source affinity: conversion rate per source
        $sourceAffinity = $this->computeSourceAffinity($cycles);

        // Region affinity: conversion rate per region
        $regionAffinity = $this->computeRegionAffinity($cycles);

        // Product affinity: conversion rate per product
        $productAffinity = $this->computeProductAffinity($cycles);

        // Time-of-day: conversion rate by hour band
        $hourMap = $this->computeTimeOfDayScore($cycles);
        $nonZeroHours = array_filter($hourMap, fn ($v) => $v > 0);
        $timeOfDay = !empty($nonZeroHours) ? array_sum($nonZeroHours) / count($nonZeroHours) : 0.0;

        // Recency: average hours since last cycle closed
        $lastClosed = $cycles->whereNotNull('closed_at')->sortByDesc('closed_at')->first();
        $recencyScore = $lastClosed
            ? min(1.0, now()->diffInHours($lastClosed->closed_at) / 168) // Normalize to 1 week
            : 0.0;

        // Overall score: weighted combination
        $overallScore = ($conversionRate * self::W_CONVERSION)
            + ($sourceAffinity['overall'] * self::W_SOURCE)
            + ($regionAffinity['overall'] * self::W_REGION)
            + ($productAffinity['overall'] * self::W_PRODUCT)
            + ($timeOfDay * self::W_TIME)
            + ($recencyScore * self::W_RECENCY)
            + ($this->normalizeHandleTime($avgHandleTime) * self::W_HANDLE_TIME);

        return [
            'conversion_rate' => $conversionRate,
            'avg_handle_time_hrs' => round($avgHandleTime, 2),
            'source_affinity_score' => $sourceAffinity['overall'],
            'region_affinity_score' => $regionAffinity['overall'],
            'product_affinity_score' => $productAffinity['overall'],
            'time_of_day_score' => $timeOfDay,
            'recency_score' => $recencyScore,
            'overall_score' => round($overallScore, 4),
            'total_cycles' => $totalCycles,
            'total_sales' => $sales,
            'feature_vector' => [
                'source_map' => $sourceAffinity['map'],
                'region_map' => $regionAffinity['map'],
                'product_map' => $productAffinity['map'],
                'hour_map' => $hourMap,
            ],
        ];
    }

    /**
     * Compute real-time factors for a (agent, lead) pair using trained model data.
     *
     * @return array<string, float>
     */
    private function computeFactors(
        PredictiveModelData $data,
        string $leadSource,
        string $leadRegion,
        string $leadProduct,
        int $nowHour,
        AgentProfile $agent,
    ): array {
        $featureVector = $data->feature_vector ?? [];

        // Source affinity for this lead's source
        $sourceMap = $featureVector['source_map'] ?? [];
        $sourceScore = $sourceMap[$leadSource] ?? $data->conversion_rate * 0.5;

        // Region affinity for this lead's region
        $regionMap = $featureVector['region_map'] ?? [];
        $regionScore = $regionMap[$leadRegion] ?? $data->conversion_rate * 0.5;

        // Product affinity for this lead's product
        $productMap = $featureVector['product_map'] ?? [];
        $productScore = $productMap[$leadProduct] ?? $data->conversion_rate * 0.5;

        // Time-of-day: use the agent's historical conversion rate at current hour
        $hourMap = $featureVector['hour_map'] ?? [];
        $timeScore = is_array($hourMap) ? ($hourMap[$nowHour] ?? $data->conversion_rate * 0.5) : $data->conversion_rate * 0.5;

        // Recency: time since last assignment (from workload)
        $workload = $agent->relationLoaded('workload') ? $agent->workload : null;
        $lastAssigned = $workload?->last_assigned_at;
        $recency = $lastAssigned
            ? min(1.0, now()->diffInHours($lastAssigned) / 24)
            : 1.0;

        // Handle time (normalized: faster = higher score)
        $handleTimeScore = $this->normalizeHandleTime($data->avg_handle_time_hrs);

        return [
            'conversion' => $data->conversion_rate,
            'source' => $sourceScore,
            'region' => $regionScore,
            'product' => $productScore,
            'time' => $timeScore,
            'recency' => $recency,
            'handle_time' => $handleTimeScore,
            'trained' => 1.0,
        ];
    }

    private function weightedScore(array $factors): float
    {
        return ($factors['conversion'] * self::W_CONVERSION)
            + ($factors['source'] * self::W_SOURCE)
            + ($factors['region'] * self::W_REGION)
            + ($factors['product'] * self::W_PRODUCT)
            + ($factors['time'] * self::W_TIME)
            + ($factors['recency'] * self::W_RECENCY)
            + ($factors['handle_time'] * self::W_HANDLE_TIME);
    }

    /**
     * @return array{overall: float, map: array<string, float>}
     */
    private function computeSourceAffinity(Collection $cycles): array
    {
        $bySource = $cycles->groupBy(fn ($c) => $c->lead?->source instanceof LeadSource
            ? $c->lead->source->value
            : (string) ($c->lead?->source ?? 'UNKNOWN'));

        $map = [];
        foreach ($bySource as $source => $group) {
            $total = $group->count();
            $sold = $group->where('outcome', 'ORDERED')->count();
            $map[$source] = $total > 0 ? $sold / $total : 0.0;
        }

        $overall = !empty($map) ? array_sum($map) / count($map) : 0.0;

        return ['overall' => $overall, 'map' => $map];
    }

    /**
     * @return array{overall: float, map: array<string, float>}
     */
    private function computeRegionAffinity(Collection $cycles): array
    {
        $byRegion = $cycles->groupBy(fn ($c) => strtoupper($c->lead?->state ?? 'UNKNOWN'));

        $map = [];
        foreach ($byRegion as $region => $group) {
            $total = $group->count();
            $sold = $group->where('outcome', 'ORDERED')->count();
            $map[$region] = $total > 0 ? $sold / $total : 0.0;
        }

        $overall = !empty($map) ? array_sum($map) / count($map) : 0.0;

        return ['overall' => $overall, 'map' => $map];
    }

    /**
     * @return array{overall: float, map: array<string, float>}
     */
    private function computeProductAffinity(Collection $cycles): array
    {
        $byProduct = $cycles->groupBy(fn ($c) => strtoupper($c->lead?->product_name ?? 'UNKNOWN'));

        $map = [];
        foreach ($byProduct as $product => $group) {
            $total = $group->count();
            $sold = $group->where('outcome', 'ORDERED')->count();
            $map[$product] = $total > 0 ? $sold / $total : 0.0;
        }

        $overall = !empty($map) ? array_sum($map) / count($map) : 0.0;

        return ['overall' => $overall, 'map' => $map];
    }

    /**
     * Compute conversion rate by hour of day.
     *
     * @return array<int, float>
     */
    private function computeTimeOfDayScore(Collection $cycles): array
    {
        $hourMap = [];
        for ($h = 0; $h < 24; $h++) {
            $hourMap[$h] = 0.0;
        }

        $byHour = $cycles->filter(fn ($c) => $c->opened_at !== null)
            ->groupBy(fn ($c) => (int) $c->opened_at->format('H'));

        foreach ($byHour as $hour => $group) {
            $total = $group->count();
            $sold = $group->where('outcome', 'ORDERED')->count();
            $hourMap[$hour] = $total > 0 ? $sold / $total : 0.0;
        }

        return $hourMap;
    }

    private function normalizeHandleTime(float $hours): float
    {
        if ($hours <= 0) {
            return 0.5; // Neutral for agents with no closed sales
        }

        // Normalize: <4h = 1.0, >48h = 0.0, linear in between
        return max(0.0, min(1.0, (48 - $hours) / 44));
    }
}
