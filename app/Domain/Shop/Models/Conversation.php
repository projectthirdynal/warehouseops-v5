<?php

declare(strict_types=1);

namespace App\Domain\Shop\Models;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Conversation extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'facebook_page_id',
        'customer_id',
        'customer_identity_id',
        'assigned_agent_id',
        'channel',
        'status',
        'priority',
        'is_flagged',
        'flag_reason',
        'flagged_at',
        'thread_key',
        'last_message_preview',
        'last_message_at',
        'unread_count',
        'tags',
        'metadata',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'flagged_at' => 'datetime',
        'is_flagged' => 'boolean',
        'tags' => 'array',
        'metadata' => 'array',
    ];

    public function facebookPage(): BelongsTo
    {
        return $this->belongsTo(FacebookPage::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function identity(): BelongsTo
    {
        return $this->belongsTo(CustomerIdentity::class, 'customer_identity_id');
    }

    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
