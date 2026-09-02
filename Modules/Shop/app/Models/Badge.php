<?php

declare(strict_types=1);

namespace Modules\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Badge extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'icon',
        'color',
        'category',
        'criteria_type',
        'criteria_value',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'criteria_value' => 'integer',
        'sort_order' => 'integer',
    ];

    public function agentBadges(): HasMany
    {
        return $this->hasMany(AgentBadge::class);
    }
}
