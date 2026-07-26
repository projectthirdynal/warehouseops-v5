<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DuplicateReviewItem extends Model
{
    protected $fillable = [
        'type',
        'primary_ref_id',
        'duplicate_ref_id',
        'primary_label',
        'duplicate_label',
        'match_method',
        'similarity_score',
        'severity',
        'status',
        'metadata',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    protected $casts = [
        'similarity_score' => 'float',
        'metadata' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
