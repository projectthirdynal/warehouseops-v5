<?php

namespace App\Services;

use App\Domain\Lead\Models\Lead;
use App\Models\DistributionRule;

class RuleConditionEvaluator
{
    /**
     * Check if a lead matches the lead-side conditions defined in a rule's filters.
     *
     * Conditions are stored under $rule->filters['conditions'] and may include:
     * - min_quality_score / max_quality_score: quality_score range
     * - lead_regions: array of regions (matched against lead state, city, barangay)
     * - lead_products: array of product names (matched against lead product_name, case-insensitive)
     * - lead_sources: array of source enum values (matched against lead source)
     * - min_amount / max_amount: amount range
     *
     * If no conditions are defined, the rule matches all leads.
     */
    public function matches(Lead $lead, ?DistributionRule $rule): bool
    {
        if (! $rule || ! $rule->filters) {
            return true;
        }

        $conditions = $rule->filters['conditions'] ?? null;

        if (empty($conditions)) {
            return true;
        }

        // Quality score range
        if (isset($conditions['min_quality_score'])) {
            if (($lead->quality_score ?? 0) < $conditions['min_quality_score']) {
                return false;
            }
        }

        if (isset($conditions['max_quality_score'])) {
            if (($lead->quality_score ?? 0) > $conditions['max_quality_score']) {
                return false;
            }
        }

        // Lead regions (state, city, barangay)
        if (! empty($conditions['lead_regions'])) {
            $leadRegionValues = array_filter([
                $lead->state,
                $lead->city,
                $lead->barangay,
            ]);

            if (! $this->matchRegions($conditions['lead_regions'], $leadRegionValues)) {
                return false;
            }
        }

        // Lead products (product_name, case-insensitive contains)
        if (! empty($conditions['lead_products'])) {
            if (! $this->matchProducts($conditions['lead_products'], $lead->product_name)) {
                return false;
            }
        }

        // Lead sources (source enum value)
        if (! empty($conditions['lead_sources'])) {
            $leadSource = $lead->source;
            if ($leadSource !== null) {
                $sourceValue = is_string($leadSource) ? $leadSource : $leadSource->value;
            } else {
                $sourceValue = null;
            }

            if (! in_array($sourceValue, $conditions['lead_sources'], true)) {
                return false;
            }
        }

        // Amount range
        if (isset($conditions['min_amount'])) {
            if ((float) ($lead->amount ?? 0) < (float) $conditions['min_amount']) {
                return false;
            }
        }

        if (isset($conditions['max_amount'])) {
            if ((float) ($lead->amount ?? 0) > (float) $conditions['max_amount']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if any of the lead's region values match the condition regions (case-insensitive).
     */
    private function matchRegions(array $conditionRegions, array $leadRegionValues): bool
    {
        if (empty($leadRegionValues)) {
            return false;
        }

        $conditionUpper = array_map('strtoupper', $conditionRegions);

        foreach ($leadRegionValues as $leadRegion) {
            if ($leadRegion && in_array(strtoupper($leadRegion), $conditionUpper, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the lead's product name matches any condition product (case-insensitive contains).
     */
    private function matchProducts(array $conditionProducts, ?string $leadProduct): bool
    {
        if (! $leadProduct) {
            return false;
        }

        $leadUpper = strtoupper($leadProduct);

        foreach ($conditionProducts as $product) {
            if (str_contains($leadUpper, strtoupper($product))) {
                return true;
            }
        }

        return false;
    }
}
