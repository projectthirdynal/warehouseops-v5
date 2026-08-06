<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Product\Models\Product;
use App\Domain\Product\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CogsVarianceAlert extends Model
{
    protected $table = 'cogs_variance_alerts';

    protected $fillable = [
        'alert_date', 'product_id', 'variant_id',
        'severity', 'alert_type',
        'actual_cost', 'standard_cost',
        'variance_amount', 'variance_pct',
        'affected_entries', 'message',
        'resolved', 'resolved_at', 'resolved_by', 'resolution_note',
    ];

    protected $casts = [
        'alert_date' => 'date',
        'actual_cost' => 'decimal:4',
        'standard_cost' => 'decimal:4',
        'variance_amount' => 'decimal:4',
        'variance_pct' => 'decimal:4',
        'affected_entries' => 'integer',
        'resolved' => 'boolean',
        'resolved_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
