<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Inventory\Models\StockCostLot;
use App\Domain\Product\Models\Product;
use App\Domain\Product\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CogsEntry extends Model
{
    protected $table = 'cogs_entries';

    protected $fillable = [
        'product_id', 'variant_id', 'waybill_id', 'order_id', 'cost_lot_id',
        'method', 'quantity', 'unit_cost', 'total_cost',
        'currency_code', 'exchange_rate', 'recorded_at', 'synced_to_qbo_at',
    ];

    protected $casts = [
        'quantity'         => 'decimal:4',
        'unit_cost'        => 'decimal:4',
        'total_cost'       => 'decimal:4',
        'exchange_rate'    => 'decimal:6',
        'recorded_at'      => 'datetime',
        'synced_to_qbo_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function costLot(): BelongsTo
    {
        return $this->belongsTo(StockCostLot::class, 'cost_lot_id');
    }
}
