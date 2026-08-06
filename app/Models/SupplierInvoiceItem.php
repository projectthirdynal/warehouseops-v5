<?php

namespace App\Models;

use App\Domain\Procurement\Models\PurchaseOrderItem;
use App\Domain\Product\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierInvoiceItem extends Model
{
    protected $fillable = [
        'supplier_invoice_id', 'po_item_id', 'product_id',
        'description', 'quantity', 'unit_price', 'tax_rate',
        'line_total', 'position',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:4',
        'tax_rate' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class);
    }

    public function poItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'po_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
