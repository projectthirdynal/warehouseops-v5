<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DuplicateMlModel extends Model
{
    protected $table = 'duplicate_ml_models';

    protected $fillable = [
        'name',
        'version',
        'feature_weights',
        'training_stats',
        'training_samples',
        'accuracy',
        'precision',
        'recall',
        'f1_score',
        'trained_at',
        'is_active',
    ];

    protected $casts = [
        'feature_weights' => 'array',
        'training_stats' => 'array',
        'trained_at' => 'datetime',
        'is_active' => 'boolean',
        'accuracy' => 'float',
        'precision' => 'float',
        'recall' => 'float',
        'f1_score' => 'float',
    ];
}
