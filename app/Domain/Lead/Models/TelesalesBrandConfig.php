<?php

declare(strict_types=1);

namespace App\Domain\Lead\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TelesalesBrandConfig extends Model
{
    protected $fillable = [
        'brand_name',
        'display_name',
        'is_active',
        'match_patterns',
        'default_max_lead_age_days',
        'default_distribution_method',
        'max_pool_quantity',
        'priority',
        'allowed_regions',
        'allowed_teams',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'match_patterns' => 'array',
        'allowed_regions' => 'array',
        'allowed_teams' => 'array',
        'default_max_lead_age_days' => 'integer',
        'max_pool_quantity' => 'integer',
        'priority' => 'integer',
    ];

    protected $attributes = [
        'is_active' => true,
        'default_distribution_method' => 'equal',
        'priority' => 0,
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Get all match patterns for ILIKE filtering on leads.product_name.
     * Falls back to the brand_name itself if no explicit patterns are set.
     *
     * @return array<int, string>
     */
    public function getMatchPatterns(): array
    {
        if (! empty($this->match_patterns)) {
            return $this->match_patterns;
        }

        return [$this->brand_name];
    }
}
