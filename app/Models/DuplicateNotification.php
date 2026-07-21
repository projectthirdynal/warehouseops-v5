<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DuplicateNotification extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'severity',
        'title',
        'message',
        'entity_type',
        'entity_id',
        'action_url',
        'action_label',
        'metadata',
        'read_at',
        'read_by',
    ];

    protected $casts = [
        'metadata' => 'array',
        'read_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'read_by');
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }
}
