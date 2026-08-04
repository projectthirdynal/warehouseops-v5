<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockAlert extends Model
{
    use HasFactory;

    public const TYPE_LOW_STOCK = 'LOW_STOCK';

    public const TYPE_OUT_OF_STOCK = 'OUT_OF_STOCK';

    public const TYPE_OVERSTOCK = 'OVERSTOCK';

    public const STATUS_OPEN = 'OPEN';

    public const STATUS_ACKNOWLEDGED = 'ACKNOWLEDGED';

    public const STATUS_RESOLVED = 'RESOLVED';

    protected $fillable = [
        'stockable_type',
        'stockable_id',
        'warehouse_id',
        'alert_type',
        'current_stock',
        'reserved_stock',
        'reorder_point',
        'suggested_reorder_qty',
        'status',
        'acknowledged_by',
        'acknowledged_at',
        'notes',
    ];

    protected $casts = [
        'current_stock' => 'integer',
        'reserved_stock' => 'integer',
        'reorder_point' => 'integer',
        'suggested_reorder_qty' => 'integer',
        'acknowledged_at' => 'datetime',
    ];

    public function stockable(): MorphTo
    {
        return $this->morphTo();
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }
}
