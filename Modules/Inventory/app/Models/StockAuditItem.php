<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;

class StockAuditItem extends Model
{
    public const STATUS_PENDING = 'PENDING';

    public const STATUS_COUNTED = 'COUNTED';

    public const STATUS_SKIPPED = 'SKIPPED';

    protected $fillable = [
        'session_id',
        'product_id',
        'supply_id',
        'variant_id',
        'location_id',
        'system_qty',
        'counted_qty',
        'variance',
        'status',
        'notes',
        'counted_by',
        'counted_at',
        'adjustment_id',
    ];

    protected $casts = [
        'system_qty' => 'integer',
        'counted_qty' => 'integer',
        'variance' => 'integer',
        'counted_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(StockAuditSession::class, 'session_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function supply(): BelongsTo
    {
        return $this->belongsTo(Supply::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'location_id');
    }

    public function countedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class, 'adjustment_id');
    }
}
