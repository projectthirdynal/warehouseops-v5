<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BudgetLine extends Model
{
    protected $fillable = [
        'budget_id', 'category', 'line_type', 'budgeted_amount',
        'threshold_percent', 'notes',
    ];

    protected $casts = [
        'budgeted_amount' => 'decimal:2',
        'threshold_percent' => 'decimal:2',
    ];

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function varianceAlerts(): HasMany
    {
        return $this->hasMany(BudgetVarianceAlert::class);
    }
}
