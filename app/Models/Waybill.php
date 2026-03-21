<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Waybill extends Model
{
    use HasFactory, SoftDeletes;

    // J&T status mapping
    public const JNT_STATUS_MAP = [
        'Delivered' => 'DELIVERED',
        'In Transit' => 'IN_TRANSIT',
        'Pending' => 'PENDING',
        'Dispatched' => 'DISPATCHED',
        'Returned' => 'RETURNED',
        'RTS' => 'RETURNED',
        'Return to Sender' => 'RETURNED',
        'Out for Delivery' => 'OUT_FOR_DELIVERY',
        'Picked Up' => 'PICKED_UP',
        'At Warehouse' => 'AT_WAREHOUSE',
    ];

    protected $fillable = [
        'waybill_number',
        'creator_code',
        'status',
        'sign_for_pictures',
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
        'item_value',
        'valuation_fee',
        'amount',
        'payment_method',
        'shipping_cost',
        'cod_amount',
        'settlement_weight',
        'courier_provider',
        'express_type',
        'rts_reason',
        'remarks',
        'sender_name',
        'sender_phone',
        'sender_province',
        'sender_city',
        'lead_id',
        'uploaded_by',
        'upload_id',
        'submitted_at',
        'signed_at',
        'dispatched_at',
        'delivered_at',
        'returned_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'shipping_cost' => 'decimal:2',
        'cod_amount' => 'decimal:2',
        'settlement_weight' => 'decimal:2',
        'item_value' => 'decimal:2',
        'valuation_fee' => 'decimal:2',
        'sign_for_pictures' => 'boolean',
        'submitted_at' => 'datetime',
        'signed_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'delivered_at' => 'datetime',
        'returned_at' => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function trackingHistory(): HasMany
    {
        return $this->hasMany(WaybillTrackingHistory::class);
    }

    public function upload(): BelongsTo
    {
        return $this->belongsTo(Upload::class);
    }

    /**
     * Map J&T status to internal status
     */
    public static function mapJntStatus(string $jntStatus): string
    {
        return self::JNT_STATUS_MAP[$jntStatus] ?? 'PENDING';
    }
}
