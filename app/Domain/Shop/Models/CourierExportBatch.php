<?php

declare(strict_types=1);

namespace App\Domain\Shop\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourierExportBatch extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_READY = 'ready';
    public const STATUS_DOWNLOADED = 'downloaded';
    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
        self::STATUS_READY,
        self::STATUS_DOWNLOADED,
        self::STATUS_ARCHIVED,
    ];

    protected $fillable = [
        'batch_number',
        'courier_code',
        'region',
        'status',
        'created_by',
        'row_count',
        'file_path',
        'exported_at',
        'downloaded_at',
        'archived_at',
        'metadata',
    ];

    protected $casts = [
        'exported_at' => 'datetime',
        'downloaded_at' => 'datetime',
        'archived_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(CourierExportRow::class);
    }
}
