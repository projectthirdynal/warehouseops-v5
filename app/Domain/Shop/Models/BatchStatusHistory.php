<?php

declare(strict_types=1);

namespace App\Domain\Shop\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BatchStatusHistory extends Model
{
    protected $table = 'batch_status_history';

    protected $fillable = [
        'courier_export_batch_id',
        'from_status',
        'to_status',
        'changed_by',
        'notes',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CourierExportBatch::class, 'courier_export_batch_id');
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
