<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Order\Models\Order;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentTransaction extends Model
{
    protected $fillable = [
        'reference_number',
        'gateway',
        'status',
        'transaction_type',
        'amount',
        'currency',
        'invoice_id',
        'order_id',
        'cod_settlement_id',
        'sender_name',
        'sender_account',
        'sender_phone',
        'recipient_name',
        'recipient_account',
        'description',
        'transaction_date',
        'verified_at',
        'verified_by',
        'reconciled_at',
        'reconciled_by',
        'reconciliation_ref',
        'gateway_response',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'transaction_date' => 'datetime',
        'verified_at' => 'datetime',
        'reconciled_at' => 'datetime',
        'gateway_response' => 'array',
    ];

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_VERIFIED = 'VERIFIED';

    public const STATUS_RECONCILED = 'RECONCILED';

    public const STATUS_FAILED = 'FAILED';

    public const STATUS_REFUNDED = 'REFUNDED';

    public const GATEWAY_GCASH = 'GCASH';

    public const GATEWAY_BANK_TRANSFER = 'BANK_TRANSFER';

    public const GATEWAY_MAYA = 'MAYA';

    public const GATEWAY_CARD = 'CARD';

    public const TYPE_INCOMING = 'INCOMING';

    public const TYPE_OUTGOING = 'OUTGOING';

    // Relationships

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function codSettlement(): BelongsTo
    {
        return $this->belongsTo(CodSettlement::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    // Scopes

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeVerified($query)
    {
        return $query->where('status', self::STATUS_VERIFIED);
    }

    public function scopeReconciled($query)
    {
        return $query->where('status', self::STATUS_RECONCILED);
    }

    public function scopeByGateway($query, string $gateway)
    {
        return $query->where('gateway', $gateway);
    }

    public function scopeIncoming($query)
    {
        return $query->where('transaction_type', self::TYPE_INCOMING);
    }

    public function scopeUnreconciled($query)
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_VERIFIED]);
    }

    // Helpers

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isVerified(): bool
    {
        return $this->status === self::STATUS_VERIFIED;
    }

    public function isReconciled(): bool
    {
        return $this->status === self::STATUS_RECONCILED;
    }

    public function canBeVerified(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function canBeReconciled(): bool
    {
        return $this->status === self::STATUS_VERIFIED;
    }
}
