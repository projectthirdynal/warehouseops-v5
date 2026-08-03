<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadQualityModel extends Model
{
    protected $fillable = [
        'model_version',
        'source_map',
        'baseline_score',
        'sample_size',
        'positive_count',
        'trained_at',
    ];

    protected $casts = [
        'source_map' => 'array',
        'baseline_score' => 'float',
        'sample_size' => 'integer',
        'positive_count' => 'integer',
        'trained_at' => 'datetime',
    ];
}
