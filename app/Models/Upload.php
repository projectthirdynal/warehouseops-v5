<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Upload extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'filename',
        'original_filename',
        'file_path',
        'type',
        'courier',
        'import_type',
        'auto_import',
        'total_rows',
        'processed_rows',
        'success_rows',
        'error_rows',
        'inserted_rows',
        'updated_rows',
        'skipped_rows',
        'total_chunks',
        'processed_chunks',
        'status',
        'errors',
        'metadata',
        'file_hash',
        'uploaded_by',
        'started_at',
        'completed_at',
        'retry_count',
        'retry_of',
        'retry_status',
    ];

    protected $casts = [
        'errors' => 'array',
        'metadata' => 'array',
        'auto_import' => 'boolean',
        'total_rows' => 'integer',
        'processed_rows' => 'integer',
        'success_rows' => 'integer',
        'error_rows' => 'integer',
        'inserted_rows' => 'integer',
        'updated_rows' => 'integer',
        'skipped_rows' => 'integer',
        'total_chunks' => 'integer',
        'processed_chunks' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'retry_count' => 'integer',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_VALIDATING = 'validating';

    public const STATUS_VALIDATION_FAILED = 'validation_failed';

    public const STATUS_VALIDATED = 'validated';

    public const STATUS_TRANSFORMING = 'transforming';

    public const STATUS_READY_TO_IMPORT = 'ready_to_import';

    public const STATUS_IMPORTING = 'importing';

    public const STATUS_READY_TO_PROCESS = 'ready_to_process';

    public const STATUS_COMPLETED_WITH_ERRORS = 'completed_with_errors';

    public const STATUS_CANCELLED = 'cancelled';

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function waybills(): HasMany
    {
        return $this->hasMany(Waybill::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(ImportChunk::class);
    }

    public function markAsProcessing(): void
    {
        $this->update(['status' => self::STATUS_PROCESSING]);
    }

    public function markAsCompleted(): void
    {
        $this->update(['status' => self::STATUS_COMPLETED, 'completed_at' => now()]);
    }

    public function markAsFailed(array $errors = []): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'errors' => $errors,
            'completed_at' => now(),
        ]);
    }

    public function incrementSuccess(): void
    {
        $this->increment('success_rows');
        $this->increment('processed_rows');
    }

    public function incrementError(): void
    {
        $this->increment('error_rows');
        $this->increment('processed_rows');
    }

    public function markAsValidationFailed(array $errors = []): void
    {
        $this->update([
            'status' => self::STATUS_VALIDATION_FAILED,
            'errors' => $errors,
            'completed_at' => now(),
        ]);
    }

    public function markAsReadyToProcess(): void
    {
        $this->update(['status' => self::STATUS_READY_TO_PROCESS]);
    }

    public function markAsCancelled(): void
    {
        $this->update([
            'status' => self::STATUS_CANCELLED,
            'completed_at' => now(),
        ]);
    }

    public function markAsCompletedWithErrors(array $errors = []): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED_WITH_ERRORS,
            'errors' => $errors,
            'completed_at' => now(),
        ]);
    }
}
