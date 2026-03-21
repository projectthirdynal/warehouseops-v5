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
        'filename',
        'original_filename',
        'type',
        'total_rows',
        'processed_rows',
        'success_rows',
        'error_rows',
        'status',
        'errors',
        'uploaded_by',
    ];

    protected $casts = [
        'errors' => 'array',
        'total_rows' => 'integer',
        'processed_rows' => 'integer',
        'success_rows' => 'integer',
        'error_rows' => 'integer',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function waybills(): HasMany
    {
        return $this->hasMany(Waybill::class);
    }

    public function markAsProcessing(): void
    {
        $this->update(['status' => self::STATUS_PROCESSING]);
    }

    public function markAsCompleted(): void
    {
        $this->update(['status' => self::STATUS_COMPLETED]);
    }

    public function markAsFailed(array $errors = []): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'errors' => $errors,
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
}
