<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Lead\Enums\PoolStatus;
use App\Domain\Lead\Models\Lead;
use App\Domain\Lead\Models\TelesalesBrandConfig;
use App\Domain\Shop\Models\AddressMapping;
use App\Models\SystemSetting;
use Illuminate\Database\Eloquent\Builder;

/**
 * Single source of truth for lead eligibility in the telesales context.
 *
 * An eligible lead is one that:
 *  - Has pool_status = AVAILABLE
 *  - Has a source_waybill_id with a delivered_at within the configured max age
 *  - Has a valid phone number
 *  - Is not linked to a blacklisted or do-not-call customer
 *  - Is not exhausted
 *  - Matches the requested brand / product / region / age filters
 *
 * The same query is used for both the inventory counters (UI display) and
 * the eventual pool reservation (Phase C). Do not implement a separate
 * query for pool creation — call this service.
 */
class LeadEligibilityService
{
    /**
     * Build the base eligibility query with all standard exclusions applied.
     *
     * @return Builder<Lead>
     */
    public function baseEligibleQuery(): Builder
    {
        $maxAgeDays = (int) SystemSetting::get('telesales_max_waybill_age_days', 60);
        $ageThreshold = now()->subDays($maxAgeDays);

        return Lead::query()
            ->where('pool_status', PoolStatus::AVAILABLE)
            ->whereNotNull('source_waybill_id')
            ->where('is_exhausted', false)
            // Join to source waybill for the age check
            ->whereHas('sourceWaybill', function (Builder $q) use ($ageThreshold) {
                $q->where('status', 'DELIVERED')
                    ->whereNotNull('delivered_at')
                    ->where('delivered_at', '>=', $ageThreshold);
            })
            // Exclude leads whose customer is blacklisted or DNC
            ->whereHas('customer', function (Builder $q) {
                $q->where(function ($sub) {
                    $sub->where('is_blacklisted', false)
                        ->orWhereNull('is_blacklisted');
                })
                    ->where(function ($sub) {
                        $sub->where('do_not_call', false)
                            ->orWhereNull('do_not_call');
                    });
            })
            // Exclude leads with invalid phones
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            // Exclude leads that are still in an active order cooldown
            ->where(function ($q) {
                $q->whereNull('cooldown_until')
                    ->orWhere('cooldown_until', '<=', now());
            });
    }

    /**
     * Apply brand filtering to an eligibility query.
     *
     * Brand matching uses the telesales_brand_configs.match_patterns array
     * (ILIKE match against leads.product_name). If no brand config is found
     * for the given brand name, falls back to a direct ILIKE on the brand name.
     *
     * @param  Builder<Lead>  $query
     * @param  string  $brandName  The brand to filter by (matches telesales_brand_configs.brand_name)
     * @return Builder<Lead>
     */
    public function applyBrandFilter(Builder $query, string $brandName): Builder
    {
        $config = TelesalesBrandConfig::where('brand_name', $brandName)->first();

        if ($config) {
            $patterns = $config->getMatchPatterns();
        } else {
            $patterns = [$brandName];
        }

        return $query->where(function ($q) use ($patterns) {
            foreach ($patterns as $pattern) {
                $q->orWhereRaw('LOWER(product_name) LIKE ?', ['%'.mb_strtolower($pattern).'%']);
            }
        });
    }

    /**
     * Apply region filtering via the address_mapping relationship.
     *
     * @param  Builder<Lead>  $query
     * @param  string  $businessRegion  NCR, North Luzon, South Luzon, Visayas, Mindanao
     * @return Builder<Lead>
     */
    public function applyRegionFilter(Builder $query, string $businessRegion): Builder
    {
        return $query->whereHas('addressMapping', function (Builder $q) use ($businessRegion) {
            $q->where('business_region', $businessRegion);
        });
    }

    /**
     * Apply province filtering via the address_mapping relationship.
     *
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    public function applyProvinceFilter(Builder $query, string $province): Builder
    {
        return $query->whereHas('addressMapping', function (Builder $q) use ($province) {
            $q->whereRaw('LOWER(province) = LOWER(?)', [$province]);
        });
    }

    /**
     * Apply city/municipality filtering via the address_mapping relationship.
     *
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    public function applyCityFilter(Builder $query, string $city): Builder
    {
        return $query->whereHas('addressMapping', function (Builder $q) use ($city) {
            $q->whereRaw('LOWER(city_municipality) = LOWER(?)', [$city]);
        });
    }

    /**
     * Apply lead age range filtering (in days, based on source waybill delivered_at).
     *
     * @param  Builder<Lead>  $query
     * @param  int  $fromDays  Minimum age in days (0 = today)
     * @param  int  $toDays  Maximum age in days
     * @return Builder<Lead>
     */
    public function applyAgeRangeFilter(Builder $query, int $fromDays, int $toDays): Builder
    {
        $now = now();
        $fromDate = $now->copy()->subDays($toDays);
        $toDate = $now->copy()->subDays($fromDays);

        return $query->whereHas('sourceWaybill', function (Builder $q) use ($fromDate, $toDate) {
            $q->where('delivered_at', '>=', $fromDate)
                ->where('delivered_at', '<=', $toDate);
        });
    }

    /**
     * Apply source filtering.
     *
     * @param  Builder<Lead>  $query
     * @param  string  $source  LeadSource enum value
     * @return Builder<Lead>
     */
    public function applySourceFilter(Builder $query, string $source): Builder
    {
        return $query->where('source', $source);
    }

    /**
     * Count eligible leads for the given filters.
     *
     * @param  array{brand?: ?string, product?: ?string, business_region?: ?string, province?: ?string, city?: ?string, age_from?: ?int, age_to?: ?int, source?: ?string}  $filters
     */
    public function countEligible(array $filters = []): int
    {
        return $this->buildFilteredQuery($filters)->count();
    }

    /**
     * Get the eligibility query with all filters applied.
     * Returns a Builder so callers can add additional constraints or paginate.
     *
     * @param  array{brand?: ?string, product?: ?string, business_region?: ?string, province?: ?string, city?: ?string, age_from?: ?int, age_to?: ?int, source?: ?string}  $filters
     * @return Builder<Lead>
     */
    public function buildFilteredQuery(array $filters = []): Builder
    {
        $query = $this->baseEligibleQuery();

        if (! empty($filters['brand'])) {
            $this->applyBrandFilter($query, $filters['brand']);
        }

        if (! empty($filters['product'])) {
            $query->whereRaw('LOWER(product_name) LIKE ?', ['%'.mb_strtolower($filters['product']).'%']);
        }

        if (! empty($filters['business_region'])) {
            $this->applyRegionFilter($query, $filters['business_region']);
        }

        if (! empty($filters['province'])) {
            $this->applyProvinceFilter($query, $filters['province']);
        }

        if (! empty($filters['city'])) {
            $this->applyCityFilter($query, $filters['city']);
        }

        if (isset($filters['age_from']) && isset($filters['age_to'])) {
            $this->applyAgeRangeFilter($query, (int) $filters['age_from'], (int) $filters['age_to']);
        }

        if (! empty($filters['source'])) {
            $this->applySourceFilter($query, $filters['source']);
        }

        return $query;
    }

    /**
     * Get inventory counters broken down by brand, region, and age bands.
     *
     * Returns a table structure suitable for the Lead Inventory UI:
     *
     *   | brand | region | 0-7 days | 8-30 days | 31-60 days | total |
     *
     * @param  array{business_region?: ?string, province?: ?string, city?: ?string}  $geoFilters
     * @return array<int, array{brand: string, region: string, age_0_7: int, age_8_30: int, age_31_60: int, total: int}>
     */
    public function getInventoryBreakdown(array $geoFilters = []): array
    {
        $brands = TelesalesBrandConfig::active()->orderBy('priority')->orderBy('brand_name')->get();
        $regions = ['NCR', 'North Luzon', 'South Luzon', 'Visayas', 'Mindanao'];

        $breakdown = [];

        foreach ($brands as $brand) {
            foreach ($regions as $region) {
                $ageBands = [
                    'age_0_7' => $this->countEligible(array_merge($geoFilters, [
                        'brand' => $brand->brand_name,
                        'business_region' => $region,
                        'age_from' => 0,
                        'age_to' => 7,
                    ])),
                    'age_8_30' => $this->countEligible(array_merge($geoFilters, [
                        'brand' => $brand->brand_name,
                        'business_region' => $region,
                        'age_from' => 8,
                        'age_to' => 30,
                    ])),
                    'age_31_60' => $this->countEligible(array_merge($geoFilters, [
                        'brand' => $brand->brand_name,
                        'business_region' => $region,
                        'age_from' => 31,
                        'age_to' => 60,
                    ])),
                ];

                $total = $ageBands['age_0_7'] + $ageBands['age_8_30'] + $ageBands['age_31_60'];

                if ($total > 0) {
                    $breakdown[] = array_merge([
                        'brand' => $brand->display_name ?? $brand->brand_name,
                        'region' => $region,
                    ], $ageBands, ['total' => $total]);
                }
            }
        }

        return $breakdown;
    }

    /**
     * Get summary counts for the inventory dashboard.
     *
     * @return array{total_eligible: int, by_brand: array<string, int>, by_region: array<string, int>}
     */
    public function getInventorySummary(): array
    {
        $brands = TelesalesBrandConfig::active()->orderBy('priority')->orderBy('brand_name')->get();
        $regions = ['NCR', 'North Luzon', 'South Luzon', 'Visayas', 'Mindanao'];

        $byBrand = [];
        foreach ($brands as $brand) {
            $byBrand[$brand->display_name ?? $brand->brand_name] = $this->countEligible([
                'brand' => $brand->brand_name,
            ]);
        }

        $byRegion = [];
        foreach ($regions as $region) {
            $byRegion[$region] = $this->countEligible([
                'business_region' => $region,
            ]);
        }

        return [
            'total_eligible' => $this->countEligible(),
            'by_brand' => $byBrand,
            'by_region' => $byRegion,
        ];
    }

    /**
     * Get available filter options for the inventory UI.
     *
     * @return array{brands: array<int, array{id: string, name: string}>, regions: array<int, string>, provinces: array<int, string>, sources: array<int, string>}
     */
    public function getFilterOptions(): array
    {
        $brands = TelesalesBrandConfig::active()
            ->orderBy('priority')
            ->orderBy('brand_name')
            ->get()
            ->map(fn ($b) => ['id' => $b->brand_name, 'name' => $b->display_name ?? $b->brand_name])
            ->values()
            ->all();

        $regions = ['NCR', 'North Luzon', 'South Luzon', 'Visayas', 'Mindanao'];

        // Get provinces that have address_mappings with a business_region set
        $provinces = AddressMapping::whereNotNull('business_region')
            ->select('province')
            ->distinct()
            ->orderBy('province')
            ->pluck('province')
            ->filter()
            ->values()
            ->all();

        $sources = [
            'DELIVERED_WAYBILL',
            'XLSX_IMPORT',
            'TELESALES_IMPORT',
            'MANUAL',
            'FACEBOOK',
            'SHOP',
            'WEB',
            'PHONE',
            'REFERRAL',
            'WALK_IN',
        ];

        return [
            'brands' => $brands,
            'regions' => $regions,
            'provinces' => $provinces,
            'sources' => $sources,
        ];
    }
}
