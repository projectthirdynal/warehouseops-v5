<?php

namespace App\Domain\Finance\Models;

use App\Domain\Procurement\Models\PurchaseOrder;
use App\Models\SupplierInvoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThreeWayMatch extends Model
{
    protected $table = 'three_way_matches';

    protected $fillable = [
        'po_id', 'supplier_invoice_id', 'status', 'match_level',
        'mismatches', 'po_total', 'grn_total', 'invoice_total',
        'variance_amount', 'matched_by', 'matched_at', 'notes',
    ];

    protected $casts = [
        'mismatches' => 'array',
        'po_total' => 'decimal:2',
        'grn_total' => 'decimal:2',
        'invoice_total' => 'decimal:2',
        'variance_amount' => 'decimal:2',
        'matched_at' => 'datetime',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id');
    }

    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class);
    }

    public function matcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by');
    }
}
