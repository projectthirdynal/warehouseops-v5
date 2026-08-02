<?php

declare(strict_types=1);

namespace App\Domain\Shop\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $fillable = [
        'conversation_id',
        'facebook_page_id',
        'customer_identity_id',
        'sent_by',
        'external_message_id',
        'direction',
        'message_type',
        'body',
        'attachments',
        'phone_candidates',
        'metadata',
        'reactions',
        'is_flagged',
        'flag_reason',
        'translated_body',
        'translated_lang',
        'raw_payload',
        'sent_at',
        'read_at',
        'send_status',
        'send_error',
        'moderation_status',
        'moderation_note',
        'moderated_at',
        'moderated_by',
    ];

    protected $casts = [
        'attachments' => 'array',
        'phone_candidates' => 'array',
        'metadata' => 'array',
        'reactions' => 'array',
        'is_flagged' => 'boolean',
        'raw_payload' => 'array',
        'sent_at' => 'datetime',
        'read_at' => 'datetime',
        'moderated_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function facebookPage(): BelongsTo
    {
        return $this->belongsTo(FacebookPage::class);
    }

    public function identity(): BelongsTo
    {
        return $this->belongsTo(CustomerIdentity::class, 'customer_identity_id');
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
