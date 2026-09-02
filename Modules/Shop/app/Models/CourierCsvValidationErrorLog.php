<?php

declare(strict_types=1);

namespace Modules\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Orders\Models\Order;

class CourierCsvValidationErrorLog extends Model
{
    protected $table = 'courier_csv_validation_error_logs';

    protected $fillable = [
        'courier_export_batch_id',
        'courier_export_row_id',
        'order_id',
        'courier_code',
        'error_type',
        'error_message',
        'context',
        'source',
    ];

    protected $casts = [
        'context' => 'array',
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
}
