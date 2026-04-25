<?php

declare(strict_types=1);

namespace App\Domain\Product\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class InventoryMovement extends Model
{
    protected $fillable = [
        'product_id',
        'variant_id',
        'warehouse_id',
        'location_id',
        'to_location_id',
        'type',
        'quantity',
        'reference_type',
        'reference_id',
        'notes',
        'batch_number',
        'expiry_date',
        'performed_by',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'quantity'    => 'integer',
        'expiry_date' => 'date',
        'approved_at' => 'datetime',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Inventory\Models\Warehouse::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Inventory\Models\WarehouseLocation::class, 'location_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'performed_by');
    }
}
