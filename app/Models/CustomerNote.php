<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'user_id',
        'note_type',
        'body',
        'tags',
        'metadata',
        'pinned_until',
    ];

    protected $casts = [
        'tags' => 'array',
        'metadata' => 'array',
        'pinned_until' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Determine if this note is currently pinned.
     */
    public function isPinned(): bool
    {
        return $this->pinned_until !== null && $this->pinned_until->isFuture();
    }
}
