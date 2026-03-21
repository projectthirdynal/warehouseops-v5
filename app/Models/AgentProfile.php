<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentProfile extends Model
{
    protected $fillable = [
        'user_id',
        'max_active_cycles',
        'product_skills',
        'regions',
        'priority_weight',
        'is_available',
        'performance_score',
        'last_assignment_at',
    ];

    protected $casts = [
        'product_skills' => 'array',
        'regions' => 'array',
        'priority_weight' => 'decimal:2',
        'is_available' => 'boolean',
        'last_assignment_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
