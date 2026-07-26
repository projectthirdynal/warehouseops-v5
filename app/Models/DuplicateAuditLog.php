<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DuplicateAuditLog extends Model
{
    protected $fillable = [
        'user_id',
        'action',
        'entity_type',
        'entity_id',
        'entity_label',
        'before_state',
        'after_state',
        'note',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'before_state' => 'array',
        'after_state' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
