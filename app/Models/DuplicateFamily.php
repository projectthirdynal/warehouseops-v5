<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DuplicateFamily extends Model
{
    protected $fillable = [
        'type',
        'group_key',
        'group_method',
        'anchor_ref_id',
        'anchor_label',
        'member_count',
        'merged_count',
        'status',
        'metadata',
        'actioned_by',
        'actioned_at',
        'action_note',
    ];

    protected $casts = [
        'member_count' => 'integer',
        'merged_count' => 'integer',
        'metadata' => 'array',
        'actioned_at' => 'datetime',
    ];

    public function members(): HasMany
    {
        return $this->hasMany(DuplicateFamilyMember::class, 'family_id');
    }

    public function actioner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actioned_by');
    }
}
