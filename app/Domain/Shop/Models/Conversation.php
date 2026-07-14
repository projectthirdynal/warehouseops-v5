<?php

declare(strict_types=1);

namespace App\Domain\Shop\Models;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Conversation extends Model
{
    use SoftDeletes;

    public const STATUS_NEW = 'new';
    public const STATUS_ASSIGNED = 'assigned';
    public const STATUS_AWAITING_CUSTOMER = 'awaiting_customer';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [
        self::STATUS_NEW,
        self::STATUS_ASSIGNED,
        self::STATUS_AWAITING_CUSTOMER,
        self::STATUS_RESOLVED,
        self::STATUS_ARCHIVED,
    ];

    public const ACTIVE_STATUSES = [
        self::STATUS_NEW,
        self::STATUS_ASSIGNED,
        self::STATUS_AWAITING_CUSTOMER,
    ];

    public const TRANSITIONS = [
        self::STATUS_NEW => [self::STATUS_ASSIGNED, self::STATUS_AWAITING_CUSTOMER, self::STATUS_RESOLVED, self::STATUS_ARCHIVED],
        self::STATUS_ASSIGNED => [self::STATUS_AWAITING_CUSTOMER, self::STATUS_RESOLVED, self::STATUS_ARCHIVED, self::STATUS_NEW],
        self::STATUS_AWAITING_CUSTOMER => [self::STATUS_ASSIGNED, self::STATUS_RESOLVED, self::STATUS_ARCHIVED],
        self::STATUS_RESOLVED => [self::STATUS_ASSIGNED, self::STATUS_AWAITING_CUSTOMER, self::STATUS_ARCHIVED],
        self::STATUS_ARCHIVED => [self::STATUS_RESOLVED, self::STATUS_ASSIGNED],
    ];

    public const AGENT_ALLOWED_TARGETS = [
        self::STATUS_ASSIGNED,
        self::STATUS_AWAITING_CUSTOMER,
        self::STATUS_RESOLVED,
    ];

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
        'snoozed_until',
        'reminder_at',
        'snooze_reason',
        'merged_into_id',
        'first_response_at',
        'resolved_at',
        'first_response_time_seconds',
        'resolution_time_seconds',
        'sentiment',
        'sentiment_score',
        'thread_key',
        'last_message_preview',
        'last_message_at',
        'typing_at',
        'draft_body',
        'unread_count',
        'metadata',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'typing_at' => 'datetime',
        'flagged_at' => 'datetime',
        'snoozed_until' => 'datetime',
        'reminder_at' => 'datetime',
        'first_response_at' => 'datetime',
        'resolved_at' => 'datetime',
        'sentiment_score' => 'float',
        'is_flagged' => 'boolean',
        'metadata' => 'array',
    ];

    public function canTransitionTo(string $targetStatus, ?string $role = null): bool
    {
        if ($this->status === $targetStatus) {
            return true;
        }

        $allowed = self::TRANSITIONS[$this->status] ?? [];

        if ($role !== null && !in_array($role, ['supervisor', 'admin', 'superadmin'], true)) {
            $allowed = array_values(array_intersect($allowed, self::AGENT_ALLOWED_TARGETS));
        }

        return in_array($targetStatus, $allowed, true);
    }

    public function allowedTransitionsForRole(?string $role = null): array
    {
        $allowed = self::TRANSITIONS[$this->status] ?? [];

        if ($role !== null && !in_array($role, ['supervisor', 'admin', 'superadmin'], true)) {
            $allowed = array_values(array_intersect($allowed, self::AGENT_ALLOWED_TARGETS));
        }

        return $allowed;
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'conversation_tag');
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'merged_into_id');
    }

    public function mergedConversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'merged_into_id');
    }

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

    public function assignmentHistories(): HasMany
    {
        return $this->hasMany(ConversationAssignmentHistory::class);
    }
}
