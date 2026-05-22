<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Product\Models\Product;
use App\Domain\Product\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockReservation extends Model
{
    protected $fillable = [
        'product_id', 'variant_id', 'warehouse_id', 'quantity',
        'reference_type', 'reference_id', 'reserved_by',
        'reserved_at', 'expires_at', 'status', 'released_at', 'released_reason',
    ];

    protected $casts = [
        'quantity'    => 'integer',
        'reserved_at' => 'datetime',
        'expires_at'  => 'datetime',
        'released_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
