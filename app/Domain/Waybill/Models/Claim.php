<?php

declare(strict_types=1);

namespace App\Domain\Waybill\Models;

use App\Domain\Waybill\Enums\ClaimStatus;
use App\Domain\Waybill\Enums\ClaimType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Claim extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'claim_number',
        'waybill_id',
        'type',
        'status',
        'description',
        'claim_amount',
        'approved_amount',
        'jnt_reference_number',
        'filed_by',
        'filed_at',
        'reviewed_by',
        'reviewed_at',
        'resolved_at',
        'resolution_notes',
    ];

    protected $casts = [
        'type'            => ClaimType::class,
        'status'          => ClaimStatus::class,
        'claim_amount'    => 'decimal:2',
        'approved_amount' => 'decimal:2',
        'filed_at'        => 'datetime',
        'reviewed_at'     => 'datetime',
        'resolved_at'     => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function waybill(): BelongsTo
    {
        return $this->belongsTo(Waybill::class);
    }

    public function filedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'filed_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeApproved($query)
    {
        return $query->whereIn('status', [ClaimStatus::APPROVED->value, ClaimStatus::SETTLED->value]);
    }

    public function scopePendingReview($query)
    {
        return $query->whereIn('status', [ClaimStatus::FILED->value, ClaimStatus::UNDER_REVIEW->value]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public static function generateClaimNumber(): string
    {
        $date = now()->setTimezone('Asia/Manila')->format('Ymd');
        $prefix = "CLM-{$date}-";

        $last = static::where('claim_number', 'like', "{$prefix}%")
            ->orderByDesc('claim_number')
            ->value('claim_number');

        $next = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
