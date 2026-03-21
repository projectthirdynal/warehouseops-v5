<?php

declare(strict_types=1);

namespace App\Domain\Waybill\Models;

use App\Domain\Lead\Models\Lead;
use App\Domain\Waybill\Enums\WaybillStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Waybill extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'waybill_number',
        'status',
        'receiver_name',
        'receiver_phone',
        'receiver_address',
        'city',
        'state',
        'barangay',
        'street',
        'postal_code',
        'item_name',
        'item_qty',
        'amount',
        'courier_provider',
        'lead_id',
        'uploaded_by',
        'dispatched_at',
        'delivered_at',
        'returned_at',
    ];

    protected $casts = [
        'status' => WaybillStatus::class,
        'amount' => 'decimal:2',
        'item_qty' => 'integer',
        'dispatched_at' => 'datetime',
        'delivered_at' => 'datetime',
        'returned_at' => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'uploaded_by');
    }

    public function trackingHistory(): HasMany
    {
        return $this->hasMany(WaybillTrackingHistory::class)->orderByDesc('tracked_at');
    }

    public function batchScanItems(): HasMany
    {
        return $this->hasMany(BatchScanItem::class);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopePending($query)
    {
        return $query->where('status', WaybillStatus::PENDING);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [
            WaybillStatus::DISPATCHED,
            WaybillStatus::PICKED_UP,
            WaybillStatus::IN_TRANSIT,
            WaybillStatus::ARRIVED_HUB,
            WaybillStatus::OUT_FOR_DELIVERY,
        ]);
    }

    public function scopeDelivered($query)
    {
        return $query->where('status', WaybillStatus::DELIVERED);
    }

    public function scopeReturned($query)
    {
        return $query->where('status', WaybillStatus::RETURNED);
    }

    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    // -------------------------------------------------------------------------
    // Accessors
    // -------------------------------------------------------------------------

    public function getFullAddressAttribute(): string
    {
        $parts = array_filter([
            $this->street,
            $this->barangay,
            $this->city,
            $this->state,
            $this->postal_code,
        ]);

        return implode(', ', $parts);
    }

    public function getIsTerminalAttribute(): bool
    {
        return $this->status->isTerminal();
    }

    // -------------------------------------------------------------------------
    // Methods
    // -------------------------------------------------------------------------

    public function canBeDispatched(): bool
    {
        return $this->status === WaybillStatus::PENDING;
    }

    public function markAsDispatched(): void
    {
        $this->update([
            'status' => WaybillStatus::DISPATCHED,
            'dispatched_at' => now(),
        ]);
    }

    public function updateStatus(WaybillStatus $newStatus, ?string $reason = null): void
    {
        $oldStatus = $this->status;

        $this->status = $newStatus;

        if ($newStatus === WaybillStatus::DELIVERED) {
            $this->delivered_at = now();
        }

        if ($newStatus === WaybillStatus::RETURNED) {
            $this->returned_at = now();
        }

        $this->save();

        // Create tracking history entry
        $this->trackingHistory()->create([
            'status' => $newStatus->value,
            'previous_status' => $oldStatus->value,
            'reason' => $reason,
            'tracked_at' => now(),
        ]);
    }
}
