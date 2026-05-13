<?php

declare(strict_types=1);

namespace App\Domain\Shop\Models;

use Illuminate\Database\Eloquent\Model;

class MetaDataDeletionRequest extends Model
{
    protected $fillable = [
        'confirmation_code',
        'app_scoped_user_id',
        'status',
        'source',
        'payload',
        'result_summary',
        'requested_at',
        'processed_at',
        'completed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'result_summary' => 'array',
        'requested_at' => 'datetime',
        'processed_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
