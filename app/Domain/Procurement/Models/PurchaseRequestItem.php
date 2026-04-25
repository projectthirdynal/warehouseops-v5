<?php

declare(strict_types=1);

namespace App\Domain\Procurement\Models;

use App\Domain\Product\Models\Product;
use App\Domain\Product\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseRequestItem extends Model
{
    protected $fillable = [
        'pr_id', 'product_id', 'supply_id', 'variant_id', 'uom_id',
        'quantity_requested', 'unit_price_estimate', 'notes',
    ];

    protected $casts = [
        'quantity_requested'  => 'integer',
        'unit_price_estimate' => 'decimal:4',
    ];

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'pr_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function lineTotal(): float
    {
        return (float) $this->quantity_requested * (float) $this->unit_price_estimate;
    }
}
