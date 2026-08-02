<?php

declare(strict_types=1);

namespace App\Domain\Shop\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacebookWebhookEvent extends Model
{
    use HasFactory;
    public const STATUS_RECEIVED = 'received';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_DEAD_LETTER = 'dead_letter';

    protected $fillable = [
        'facebook_page_id',
        'event_id',
        'event_key',
        'object',
        'event_type',
        'sender_psid',
        'recipient_id',
        'payload',
        'signature_valid',
        'status',
        'retry_count',
        'processed_at',
        'error_message',
        'last_error',
    ];

    protected $casts = [
        'payload' => 'array',
        'signature_valid' => 'boolean',
        'processed_at' => 'datetime',
    ];

    public function facebookPage(): BelongsTo
    {
        return $this->belongsTo(FacebookPage::class);
    }

    public function markProcessing(): void
    {
        $this->forceFill([
            'status' => self::STATUS_PROCESSING,
        ])->save();
    }

    public function markProcessed(): void
    {
        $this->forceFill([
            'status' => self::STATUS_PROCESSED,
            'processed_at' => now(),
            'error_message' => null,
            'last_error' => null,
        ])->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'retry_count' => $this->retry_count + 1,
            'last_error' => $error,
            'error_message' => $error,
        ])->save();
    }

    public function markDeadLetter(string $error): void
    {
        $this->forceFill([
            'status' => self::STATUS_DEAD_LETTER,
            'last_error' => $error,
            'error_message' => $error,
        ])->save();
    }

    public function markRejected(string $reason): void
    {
        $this->forceFill([
            'status' => self::STATUS_REJECTED,
            'error_message' => $reason,
            'processed_at' => now(),
        ])->save();
    }
}
