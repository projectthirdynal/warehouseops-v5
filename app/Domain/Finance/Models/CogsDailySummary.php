<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Product\Models\Product;
use App\Domain\Product\Models\ProductVariant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CogsDailySummary extends Model
{
    protected $table = 'cogs_daily_summaries';

    protected $fillable = [
        'summary_date', 'product_id', 'variant_id',
        'total_quantity', 'total_cost', 'avg_unit_cost',
        'standard_cost', 'variance_amount', 'variance_pct',
        'entries_count', 'orders_count',
    ];

    protected $casts = [
        'summary_date' => 'date',
        'total_quantity' => 'decimal:4',
        'total_cost' => 'decimal:4',
        'avg_unit_cost' => 'decimal:4',
        'standard_cost' => 'decimal:4',
        'variance_amount' => 'decimal:4',
        'variance_pct' => 'decimal:4',
        'entries_count' => 'integer',
        'orders_count' => 'integer',
    ];

    /**
     * Store summary_date as Y-m-d (not Y-m-d H:i:s)
     * so SQLite string comparisons match correctly.
     */
    public function setSummaryDateAttribute($value): void
    {
        $this->attributes['summary_date'] = $value instanceof \DateTimeInterface
            ? $value->format('Y-m-d')
            : Carbon::parse($value)->format('Y-m-d');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
