<?php

declare(strict_types=1);

namespace Modules\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Milestone extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'metric',
        'target_value',
        'period',
        'reward_badge_id',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'target_value' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function rewardBadge(): BelongsTo
    {
        return $this->belongsTo(Badge::class, 'reward_badge_id');
    }

    public function agentMilestones(): HasMany
    {
        return $this->hasMany(AgentMilestone::class);
    }
}
