<?php

namespace App\Services;

use App\Domain\Lead\Enums\LeadSource;
use App\Domain\Lead\Models\Lead;
use App\Models\Customer;
use App\Models\LeadCycle;
use App\Models\LeadQualityModel;
use Illuminate\Support\Facades\Cache;

/**
 * Leads & Distribution Engine — Phase 1 C1: Lead Scoring.
 * Leads & Distribution Engine — Phase 4 L2: Quality ML Model.
 *
 * Computes a 0–100 quality score for a lead from three weighted signals:
 *   - Source quality (30%) — how trustworthy/high-converting the lead source is.
 *     When a trained model is available (see `train()`), the per-source score is
 *     auto-learned from historical LeadCycle conversion outcomes instead of the
 *     static SOURCE_SCORES fallback table below.
 *   - Demographics (25%) — completeness/validity of contact and location data.
 *   - History (45%) — prior customer delivery/conversion history (or lead cycle
 *     outcomes for leads without a linked customer). Weighted heaviest since past
 *     behavior is the strongest predictor of future conversion.
 *
 * The resulting `quality_score` is persisted on the Lead and used by the
 * distribution engine to prioritize which leads get assigned first.
 */
class LeadScoringService
{
    private const MODEL_VERSION = 'ml-v1';

    private const MODEL_CACHE_KEY = 'lead_quality_model:'.self::MODEL_VERSION;

    private const MODEL_CACHE_TTL = 3600;

    /** Minimum closed cycles a source must have before its learned score is trusted. */
    private const MIN_SAMPLES_PER_SOURCE = 10;

    private const SOURCE_SCORES = [
        'REFERRAL' => 90,
        'DELIVERED_WAYBILL' => 85,
        'WALK_IN' => 80,
        'PHONE' => 75,
        'TELESALES_IMPORT' => 70,
        'WEB' => 65,
        'SHOP' => 60,
        'FACEBOOK' => 60,
        'MANUAL' => 55,
        'XLSX_IMPORT' => 50,
        'WAYBILL' => 50,
    ];

    private const DEFAULT_SOURCE_SCORE = 55;

    private const WEIGHT_SOURCE = 0.30;

    private const WEIGHT_DEMOGRAPHICS = 0.25;

    private const WEIGHT_HISTORY = 0.45;

    /**
     * Score a persisted Lead across all three signals.
     *
     * @return array{total: int, source_score: int, demographic_score: int, history_score: int}
     */
    public function score(Lead $lead): array
    {
        $sourceScore = $this->scoreSourceValue($lead->source instanceof LeadSource ? $lead->source->value : (string) $lead->source);
        $demographicScore = $this->scoreDemographicsFromArray([
            'address' => $lead->address,
            'city' => $lead->city,
            'state' => $lead->state,
            'barangay' => $lead->barangay,
            'phone' => $lead->phone,
            'product_name' => $lead->product_name,
            'amount' => $lead->amount,
        ]);
        $historyScore = $this->scoreHistory($lead);

        return $this->weightedTotal($sourceScore, $demographicScore, $historyScore);
    }

    /**
     * Recompute and persist the quality score for an existing lead.
     */
    public function rescoreLead(Lead $lead): int
    {
        $result = $this->score($lead);

        $lead->forceFill([
            'quality_score' => $result['total'],
            'last_scored_at' => now(),
        ])->save();

        return $result['total'];
    }

    /**
     * Compute a quality score from raw import/creation data, before (or instead of)
     * a fully persisted Lead model. Used by import services and lead-creation jobs
     * so every lead gets a consistent score at the moment it enters the pool.
     *
     * @param  array<string, mixed>  $data  Expects any of: source, address, city, state,
     *                                      barangay, phone, product_name, amount.
     */
    public function scoreFromImportData(array $data, ?Customer $customer = null): int
    {
        $sourceScore = $this->scoreSourceValue((string) ($data['source'] ?? 'MANUAL'));
        $demographicScore = $this->scoreDemographicsFromArray($data);
        $historyScore = $customer ? $this->scoreCustomerHistory($customer) : 50;

        return $this->weightedTotal($sourceScore, $demographicScore, $historyScore)['total'];
    }

    /**
     * Rescore a batch of leads that have never been scored or are stale (>7 days).
     * Intended for the scheduled `leads:rescore` command so scores stay fresh as
     * customer delivery history accumulates over time.
     *
     * @return array{rescored: int}
     */
    public function bulkRescore(int $limit = 200): array
    {
        $leads = Lead::with(['customer', 'cycles'])
            ->where(function ($q) {
                $q->whereNull('last_scored_at')
                    ->orWhere('last_scored_at', '<', now()->subDays(7));
            })
            ->limit($limit)
            ->get();

        foreach ($leads as $lead) {
            $this->rescoreLead($lead);
        }

        return ['rescored' => $leads->count()];
    }

    /**
     * Retrain the source-quality signal from historical LeadCycle conversion outcomes.
     * Computes, per lead source, the actual conversion rate (ORDERED / total closed
     * cycles) and persists it as the learned `source_map`. Sources with fewer than
     * `MIN_SAMPLES_PER_SOURCE` closed cycles are excluded and fall back to the static
     * SOURCE_SCORES table at scoring time so sparse sources aren't over-trusted.
     *
     * @return array{sample_size: int, positive_count: int, sources_trained: int}
     */
    public function train(): array
    {
        $cycles = LeadCycle::whereNotNull('outcome')
            ->with('lead:id,source')
            ->get()
            ->filter(fn (LeadCycle $c) => $c->lead !== null);

        $sampleSize = $cycles->count();
        $positiveCount = $cycles->where('outcome', 'ORDERED')->count();
        $baseline = $sampleSize > 0 ? round(($positiveCount / $sampleSize) * 100, 2) : self::DEFAULT_SOURCE_SCORE;

        $bySource = $cycles->groupBy(fn (LeadCycle $c) => $c->lead->source instanceof LeadSource
            ? $c->lead->source->value
            : strtoupper((string) $c->lead->source));

        $sourceMap = [];
        foreach ($bySource as $source => $group) {
            if ($group->count() < self::MIN_SAMPLES_PER_SOURCE) {
                continue;
            }

            $sold = $group->where('outcome', 'ORDERED')->count();
            $sourceMap[$source] = round(($sold / $group->count()) * 100, 2);
        }

        LeadQualityModel::updateOrCreate(
            ['model_version' => self::MODEL_VERSION],
            [
                'source_map' => $sourceMap,
                'baseline_score' => $baseline,
                'sample_size' => $sampleSize,
                'positive_count' => $positiveCount,
                'trained_at' => now(),
            ]
        );

        Cache::forget(self::MODEL_CACHE_KEY);

        return [
            'sample_size' => $sampleSize,
            'positive_count' => $positiveCount,
            'sources_trained' => count($sourceMap),
        ];
    }

    private function getTrainedModel(): ?LeadQualityModel
    {
        return Cache::remember(
            self::MODEL_CACHE_KEY,
            self::MODEL_CACHE_TTL,
            fn () => LeadQualityModel::where('model_version', self::MODEL_VERSION)->first()
        );
    }

    private function scoreSourceValue(string $source): int
    {
        $key = strtoupper(trim($source));

        $model = $this->getTrainedModel();
        if ($model && isset($model->source_map[$key])) {
            return (int) round($model->source_map[$key]);
        }

        return self::SOURCE_SCORES[$key] ?? self::DEFAULT_SOURCE_SCORE;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function scoreDemographicsFromArray(array $data): int
    {
        $score = 40; // Base — some contact info always required to create a lead

        if (! empty($data['address']) || ! empty($data['city'])) {
            $score += 15;
        }

        if (! empty($data['state']) && ! empty($data['city']) && ! empty($data['barangay'])) {
            $score += 15;
        }

        if (! empty($data['product_name'])) {
            $score += 10;
        }

        if (! empty($data['amount']) && is_numeric($data['amount']) && (float) $data['amount'] > 0) {
            $score += 10;
        }

        if ($this->isValidPhMobile($data['phone'] ?? null)) {
            $score += 10;
        }

        return min(100, $score);
    }

    private function isValidPhMobile(mixed $phone): bool
    {
        if (empty($phone) || ! is_string($phone)) {
            return false;
        }

        return (bool) preg_match('/^(\+639\d{9}|09\d{9})$/', trim($phone));
    }

    private function scoreHistory(Lead $lead): int
    {
        $customer = $lead->relationLoaded('customer')
            ? $lead->customer
            : ($lead->customer_id ? $lead->customer()->first() : null);

        if ($customer) {
            return $this->scoreCustomerHistory($customer);
        }

        return $this->scoreLeadCycleHistory($lead);
    }

    private function scoreCustomerHistory(Customer $customer): int
    {
        if ($customer->is_blacklisted) {
            return 0;
        }

        if (! $customer->total_orders) {
            return 50; // Neutral — no purchase history yet
        }

        $score = (int) round((float) ($customer->success_rate ?? 0));

        // Repeat-buyer bonus rewards proven, recurring customers
        if ($customer->total_orders >= 3) {
            $score += 10;
        } elseif ($customer->total_orders >= 2) {
            $score += 5;
        }

        return max(0, min(100, $score));
    }

    private function scoreLeadCycleHistory(Lead $lead): int
    {
        if (! $lead->exists) {
            return 50;
        }

        $cycles = $lead->relationLoaded('cycles') ? $lead->cycles : $lead->cycles()->get();

        if ($cycles->isEmpty()) {
            return 50;
        }

        $closed = $cycles->whereNotNull('outcome');
        if ($closed->isEmpty()) {
            return 50;
        }

        $sales = $closed->where('outcome', 'ORDERED')->count();

        return (int) round(($sales / $closed->count()) * 100);
    }

    /**
     * @return array{total: int, source_score: int, demographic_score: int, history_score: int}
     */
    private function weightedTotal(int $sourceScore, int $demographicScore, int $historyScore): array
    {
        $total = ($sourceScore * self::WEIGHT_SOURCE)
            + ($demographicScore * self::WEIGHT_DEMOGRAPHICS)
            + ($historyScore * self::WEIGHT_HISTORY);

        return [
            'total' => max(0, min(100, (int) round($total))),
            'source_score' => $sourceScore,
            'demographic_score' => $demographicScore,
            'history_score' => $historyScore,
        ];
    }
}
