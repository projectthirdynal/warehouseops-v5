<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoachingNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'agent_id',
        'author_id',
        'category',
        'priority',
        'subject',
        'body',
        'action_items',
        'resolved_at',
        'resolved_by',
        'resolution_note',
    ];

    protected $casts = [
        'action_items' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeUnresolved($query)
    {
        return $query->whereNull('resolved_at');
    }

    public function scopeResolved($query)
    {
        return $query->whereNotNull('resolved_at');
    }

    public function getIsResolvedAttribute(): bool
    {
        return $this->resolved_at !== null;
    }
}
