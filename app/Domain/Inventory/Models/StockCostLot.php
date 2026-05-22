<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Procurement\Models\ReceivingReportItem;
use App\Domain\Product\Models\Product;
use App\Domain\Product\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCostLot extends Model
{
    protected $fillable = [
        'product_id', 'variant_id', 'warehouse_id', 'grn_item_id',
        'quantity_received', 'quantity_remaining', 'unit_cost',
        'currency_code', 'exchange_rate', 'received_at', 'expiry_date', 'batch_number',
    ];

    protected $casts = [
        'quantity_received'  => 'decimal:4',
        'quantity_remaining' => 'decimal:4',
        'unit_cost'          => 'decimal:4',
        'exchange_rate'      => 'decimal:6',
        'received_at'        => 'datetime',
        'expiry_date'        => 'date',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function grnItem(): BelongsTo
    {
        return $this->belongsTo(ReceivingReportItem::class, 'grn_item_id');
    }
}
