<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PredictiveModelData extends Model
{
    protected $fillable = [
        'agent_id',
        'model_version',
        'conversion_rate',
        'avg_handle_time_hrs',
        'source_affinity_score',
        'region_affinity_score',
        'product_affinity_score',
        'time_of_day_score',
        'recency_score',
        'overall_score',
        'total_cycles',
        'total_sales',
        'feature_vector',
        'trained_at',
    ];

    protected $casts = [
        'feature_vector' => 'array',
        'trained_at' => 'datetime',
        'conversion_rate' => 'float',
        'avg_handle_time_hrs' => 'float',
        'source_affinity_score' => 'float',
        'region_affinity_score' => 'float',
        'product_affinity_score' => 'float',
        'time_of_day_score' => 'float',
        'recency_score' => 'float',
        'overall_score' => 'float',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }
}
