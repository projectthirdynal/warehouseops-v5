<?php

declare(strict_types=1);

namespace App\Domain\Shop\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

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

    public const STATUS_TRANSITIONS = [
        self::STATUS_PENDING     => [self::STATUS_PROCESSING, self::STATUS_ARCHIVED],
        self::STATUS_PROCESSING  => [self::STATUS_READY, self::STATUS_PENDING],
        self::STATUS_READY       => [self::STATUS_DOWNLOADED, self::STATUS_ARCHIVED],
        self::STATUS_DOWNLOADED  => [self::STATUS_ARCHIVED, self::STATUS_READY],
        self::STATUS_ARCHIVED    => [],
    ];

    public const STATUS_LABELS = [
        self::STATUS_PENDING    => 'Pending',
        self::STATUS_PROCESSING => 'Processing',
        self::STATUS_READY      => 'Ready',
        self::STATUS_DOWNLOADED => 'Downloaded',
        self::STATUS_ARCHIVED   => 'Archived',
    ];

    public const STATUS_COLORS = [
        self::STATUS_PENDING    => 'gray',
        self::STATUS_PROCESSING => 'blue',
        self::STATUS_READY      => 'green',
        self::STATUS_DOWNLOADED => 'indigo',
        self::STATUS_ARCHIVED   => 'slate',
    ];

    protected $fillable = [
        'batch_number',
        'courier_code',
        'region',
        'status',
        'created_by',
        'row_count',
        'file_path',
        'file_size',
        'file_hash',
        'file_generated_at',
        'exported_at',
        'downloaded_at',
        'archived_at',
        'metadata',
        'notes',
    ];

    protected $casts = [
        'exported_at' => 'datetime',
        'downloaded_at' => 'datetime',
        'archived_at' => 'datetime',
        'file_generated_at' => 'datetime',
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

    public function statusHistory(): HasMany
    {
        return $this->hasMany(BatchStatusHistory::class, 'courier_export_batch_id')->orderByDesc('id');
    }

    public function errorLogs(): HasMany
    {
        return $this->hasMany(BatchItemErrorLog::class, 'courier_export_batch_id')->orderByDesc('id');
    }

    public function canTransitionTo(string $targetStatus): bool
    {
        return in_array($targetStatus, self::STATUS_TRANSITIONS[$this->status] ?? [], true);
    }

    public function transitionTo(string $toStatus, ?string $notes = null, ?int $userId = null): self
    {
        if (! in_array($toStatus, self::STATUSES, true)) {
            throw new \InvalidArgumentException("Invalid batch status: {$toStatus}");
        }

        if ($toStatus === $this->status) {
            return $this;
        }

        $fromStatus = $this->status;

        BatchStatusHistory::query()->create([
            'courier_export_batch_id' => $this->id,
            'from_status'             => $fromStatus,
            'to_status'               => $toStatus,
            'changed_by'              => $userId ?? Auth::id(),
            'notes'                   => $notes,
        ]);

        $updates = ['status' => $toStatus];

        if ($toStatus === self::STATUS_DOWNLOADED) {
            $updates['downloaded_at'] = now();
        }
        if ($toStatus === self::STATUS_ARCHIVED) {
            $updates['archived_at'] = now();
        }
        if ($toStatus === self::STATUS_READY) {
            $updates['exported_at'] = $this->exported_at ?? now();
        }

        $this->forceFill($updates)->save();

        return $this->refresh();
    }
}
