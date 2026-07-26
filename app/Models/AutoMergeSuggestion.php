<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutoMergeSuggestion extends Model
{
    protected $fillable = [
        'target_customer_id',
        'source_customer_id',
        'confidence_score',
        'match_reasons',
        'merge_preview',
        'status',
        'actioned_by',
        'actioned_at',
        'action_note',
    ];

    protected $casts = [
        'confidence_score' => 'float',
        'match_reasons' => 'array',
        'merge_preview' => 'array',
        'actioned_at' => 'datetime',
    ];

    public function targetCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'target_customer_id');
    }

    public function sourceCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'source_customer_id');
    }

    public function actioner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actioned_by');
    }
}
