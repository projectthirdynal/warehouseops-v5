<?php

declare(strict_types=1);

namespace App\Domain\Procurement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceivingReportItem extends Model
{
    protected $fillable = [
        'grn_id', 'po_item_id', 'quantity_received', 'quantity_rejected',
        'rejection_reason', 'condition', 'batch_number', 'expiry_date', 'notes',
    ];

    protected $casts = [
        'quantity_received' => 'integer',
        'quantity_rejected' => 'integer',
        'expiry_date'       => 'date',
    ];

    public function receivingReport(): BelongsTo
    {
        return $this->belongsTo(ReceivingReport::class, 'grn_id');
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'po_item_id');
    }
}
