<?php

declare(strict_types=1);

namespace App\Domain\Shop\Models;

use App\Domain\Order\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BatchItemErrorLog extends Model
{
    protected $table = 'batch_item_error_logs';

    protected $fillable = [
        'courier_export_batch_id',
        'courier_export_row_id',
        'order_id',
        'error_type',
        'error_message',
        'severity',
        'resolution',
        'resolved_at',
        'resolved_by',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CourierExportBatch::class, 'courier_export_batch_id');
    }

    public function row(): BelongsTo
    {
        return $this->belongsTo(CourierExportRow::class, 'courier_export_row_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
