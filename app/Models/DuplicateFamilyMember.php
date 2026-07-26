<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DuplicateFamilyMember extends Model
{
    protected $fillable = [
        'family_id',
        'customer_id',
        'is_anchor',
        'member_data',
        'match_reason',
        'similarity_score',
    ];

    protected $casts = [
        'is_anchor' => 'boolean',
        'similarity_score' => 'float',
        'member_data' => 'array',
    ];

    public function family(): BelongsTo
    {
        return $this->belongsTo(DuplicateFamily::class, 'family_id');
    }
}
