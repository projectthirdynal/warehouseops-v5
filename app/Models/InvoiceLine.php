<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id', 'position',
        'product_id', 'product_ref', 'description', 'unit',
        'qty', 'unit_price', 'discount_pct', 'discount_amount',
        'tax_rate', 'tax_amount', 'total_ht', 'total_ttc',
    ];

    protected $casts = [
        'qty'             => 'decimal:3',
        'unit_price'      => 'decimal:2',
        'discount_pct'    => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_rate'        => 'decimal:2',
        'tax_amount'      => 'decimal:2',
        'total_ht'        => 'decimal:2',
        'total_ttc'       => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
