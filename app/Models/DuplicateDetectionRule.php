<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DuplicateDetectionRule extends Model
{
    protected $fillable = [
        'name',
        'type',
        'match_method',
        'is_enabled',
        'priority',
        'config',
        'description',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'priority' => 'integer',
        'config' => 'array',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
