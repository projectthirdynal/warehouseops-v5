<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentProfile extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id',
        'max_active_cycles',
        'product_skills',
        'regions',
        'priority_weight',
        'is_available',
        'last_seen_at',
        'performance_score',
        'last_assignment_at',
        'distribution_weight',
        'auto_assign_enabled',
        'shift_start',
        'shift_end',
        'max_daily_leads',
        'concurrent_lead_cap',
        'preferred_lead_sources',
        'excluded_regions',
        'category_skills',
        'max_active_conversations',
        'overflow_enabled',
    ];

    protected $casts = [
        'product_skills' => 'array',
        'regions' => 'array',
        'priority_weight' => 'decimal:2',
        'is_available' => 'boolean',
        'last_seen_at' => 'datetime',
        'last_assignment_at' => 'datetime',
        'distribution_weight' => 'decimal:2',
        'auto_assign_enabled' => 'boolean',
        'max_daily_leads' => 'integer',
        'concurrent_lead_cap' => 'integer',
        'preferred_lead_sources' => 'array',
        'excluded_regions' => 'array',
        'category_skills' => 'array',
        'max_active_conversations' => 'integer',
        'overflow_enabled' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
