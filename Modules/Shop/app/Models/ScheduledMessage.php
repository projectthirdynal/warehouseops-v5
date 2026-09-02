<?php

declare(strict_types=1);

namespace Modules\Shop\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledMessage extends Model
{
    protected $fillable = [
        'conversation_id',
        'facebook_page_id',
        'customer_identity_id',
        'body',
        'quick_replies',
        'scheduled_at',
        'status',
        'sent_message_id',
        'created_by',
        'error_message',
    ];

    protected $casts = [
        'quick_replies' => 'array',
        'scheduled_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function facebookPage(): BelongsTo
    {
        return $this->belongsTo(FacebookPage::class);
    }

    public function sentMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'sent_message_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
