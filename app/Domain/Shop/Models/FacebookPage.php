<?php

declare(strict_types=1);

namespace App\Domain\Shop\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FacebookPage extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'facebook_account_id',
        'connected_by',
        'page_id',
        'page_name',
        'category',
        'business_id',
        'page_access_token',
        'token_expires_at',
        'connected_status',
        'webhook_status',
        'last_sync_at',
        'metadata',
    ];

    protected $casts = [
        'page_access_token' => 'encrypted',
        'token_expires_at' => 'datetime',
        'last_sync_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(FacebookAccount::class, 'facebook_account_id');
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    public function identities(): HasMany
    {
        return $this->hasMany(CustomerIdentity::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function assignmentRules(): HasMany
    {
        return $this->hasMany(PageAssignmentRule::class);
    }

    public function statusLabels(): HasMany
    {
        return $this->hasMany(PageStatusLabel::class);
    }

    public function statusRules(): HasMany
    {
        return $this->hasMany(PageStatusRule::class);
    }
}
