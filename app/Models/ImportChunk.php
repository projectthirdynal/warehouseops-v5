<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportChunk extends Model
{
    use HasFactory;

    protected $fillable = [
        'upload_id',
        'chunk_number',
        'status',
        'rows_count',
        'inserted_count',
        'updated_count',
        'error_count',
        'errors',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'errors' => 'array',
        'rows_count' => 'integer',
        'inserted_count' => 'integer',
        'updated_count' => 'integer',
        'error_count' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public function upload(): BelongsTo
    {
        return $this->belongsTo(Upload::class);
    }

    public function markAsProcessing(): void
    {
        $this->update([
            'status' => self::STATUS_PROCESSING,
            'started_at' => now(),
        ]);
    }

    public function markAsCompleted(array $counts): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'inserted_count' => $counts['inserted'] ?? 0,
            'updated_count' => $counts['updated'] ?? 0,
            'error_count' => $counts['errors'] ?? 0,
            'completed_at' => now(),
        ]);
    }

    public function markAsFailed(array $errors = []): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'errors' => $errors,
            'completed_at' => now(),
        ]);
    }
}
