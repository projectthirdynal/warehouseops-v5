<?php

declare(strict_types=1);

namespace App\Domain\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacebookWebhookEvent extends Model
{
    protected $fillable = [
        'facebook_page_id',
        'event_id',
        'object',
        'event_type',
        'sender_psid',
        'recipient_id',
        'payload',
        'signature_valid',
        'processed_at',
        'error_message',
    ];

    protected $casts = [
        'payload' => 'array',
        'signature_valid' => 'boolean',
        'processed_at' => 'datetime',
    ];

    public function facebookPage(): BelongsTo
    {
        return $this->belongsTo(FacebookPage::class);
    }
}
