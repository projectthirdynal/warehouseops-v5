<?php

declare(strict_types=1);

namespace App\Domain\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BroadcastCampaign extends Model
{
    protected $fillable = [
        'name',
        'description',
        'facebook_page_id',
        'status',
        'targeting',
        'split_type',
        'split_percentage',
        'total_recipients',
        'sent_count',
        'delivered_count',
        'read_count',
        'replied_count',
        'failed_count',
        'scheduled_at',
        'started_at',
        'completed_at',
        'created_by',
    ];

    protected $casts = [
        'targeting' => 'array',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function facebookPage(): BelongsTo
    {
        return $this->belongsTo(FacebookPage::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(BroadcastVariant::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(BroadcastRecipient::class);
    }
}
