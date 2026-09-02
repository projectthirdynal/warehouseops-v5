<?php

namespace App\Models;

use Modules\Leads\Enums\DistributionStrategy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DistributionRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'strategy',
        'priority',
        'filters',
        'weight_formula',
        'is_active',
        'supervisor_id',
    ];

    protected $casts = [
        'strategy' => DistributionStrategy::class,
        'filters' => 'array',
        'weight_formula' => 'array',
        'is_active' => 'boolean',
        'priority' => 'integer',
    ];

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('priority');
    }
}
