<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadPoolAudit extends Model
{
    protected $table = 'lead_pool_audit';

    protected $fillable = [
        'lead_id',
        'lead_cycle_id',
        'user_id',
        'action',
        'old_value',
        'new_value',
        'metadata',
        'ip_address',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function leadCycle(): BelongsTo
    {
        return $this->belongsTo(LeadCycle::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
