<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsLog extends Model
{
    protected $fillable = [
        'campaign_id',
        'sequence_id',
        'waybill_id',
        'lead_id',
        'phone',
        'message',
        'status',
        'external_id',
        'error_message',
        'cost',
        'sent_at',
        'delivered_at',
    ];

    protected $casts = [
        'cost' => 'decimal:4',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(SmsCampaign::class, 'campaign_id');
    }

    public function sequence(): BelongsTo
    {
        return $this->belongsTo(SmsSequence::class, 'sequence_id');
    }

    public function waybill(): BelongsTo
    {
        return $this->belongsTo(Waybill::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
