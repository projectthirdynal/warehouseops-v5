<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketCannedResponse extends Model
{
    use SoftDeletes;

    protected $table = 'ticket_canned_responses';

    protected $fillable = [
        'title',
        'body',
        'category',
        'is_active',
        'usage_count',
        'created_by',
    ];

    protected $casts = [
        'is_active'   => 'boolean',
        'usage_count' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
