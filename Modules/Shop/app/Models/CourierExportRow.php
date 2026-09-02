<?php

declare(strict_types=1);

namespace Modules\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Orders\Models\Order;

class CourierExportRow extends Model
{
    protected $fillable = [
        'courier_export_batch_id',
        'order_id',
        'row_number',
        'status',
        'receiver_name',
        'phone_number',
        'complete_address',
        'province',
        'city',
        'barangay',
        'landmark',
        'product_name',
        'cod_amount',
        'quantity',
        'remarks',
        'payload',
        'error_message',
        'exported_at',
    ];

    protected $casts = [
        'cod_amount' => 'decimal:2',
        'payload' => 'array',
        'exported_at' => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CourierExportBatch::class, 'courier_export_batch_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
