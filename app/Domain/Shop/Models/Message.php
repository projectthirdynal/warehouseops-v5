<?php

declare(strict_types=1);

namespace App\Domain\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $fillable = [
        'conversation_id',
        'facebook_page_id',
        'customer_identity_id',
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
        'raw_payload',
        'sent_at',
        'read_at',
        'send_status',
        'send_error',
        'retry_count',
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
}
