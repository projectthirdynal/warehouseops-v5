<?php

declare(strict_types=1);

namespace App\Domain\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BroadcastVariant extends Model
{
    protected $fillable = [
        'broadcast_campaign_id',
        'label',
        'body',
        'quick_replies',
        'recipient_count',
        'sent_count',
        'delivered_count',
        'read_count',
        'replied_count',
        'failed_count',
    ];

    protected $casts = [
        'quick_replies' => 'array',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(BroadcastCampaign::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(BroadcastRecipient::class);
    }
}
