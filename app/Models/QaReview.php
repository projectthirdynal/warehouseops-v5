<?php

namespace App\Models;

use App\Domain\Lead\Models\Lead;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QaReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'lead_id',
        'reviewer_id',
        'qa_level',
        'qa_status',
        'decision',
        'notes',
        'checklist',
        'reviewed_at',
    ];

    protected $casts = [
        'qa_level' => 'integer',
        'checklist' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
