<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentBurnoutPrediction extends Model
{
    use HasFactory;

    protected $fillable = [
        'agent_id',
        'risk_score',
        'risk_level',
        'features',
        'recommendation',
        'model_version',
        'calculated_at',
    ];

    protected $casts = [
        'risk_score' => 'integer',
        'features' => 'array',
        'calculated_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }
}
