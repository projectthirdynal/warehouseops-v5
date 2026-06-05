<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupplierInvoice extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'supplier_invoices';

    protected $fillable = [
        'ref', 'status',
        'third_party_id', 'supplier_name', 'supplier_email', 'supplier_phone', 'supplier_address',
        'order_id', 'invoice_id',
        'date_invoice', 'date_due', 'date_receipt',
        'payment_terms', 'currency',
        'subtotal', 'discount_amount', 'tax_rate', 'tax_amount', 'shipping_amount',
        'total_amount', 'amount_paid', 'amount_due',
        'notes', 'terms',
        'cancel_reason', 'cancelled_at',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'date_invoice'    => 'date',
        'date_due'        => 'date',
        'date_receipt'    => 'date',
        'subtotal'        => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_rate'        => 'decimal:2',
        'tax_amount'      => 'decimal:2',
        'shipping_amount' => 'decimal:2',
        'total_amount'    => 'decimal:2',
        'amount_paid'     => 'decimal:2',
        'amount_due'      => 'decimal:2',
        'cancelled_at'    => 'datetime',
    ];

    public static function generateRef(): string
    {
        $year = now()->year;
        $count = self::whereYear('created_at', $year)->count() + 1;
        return sprintf('SINV-%s-%05d', $year, $count);
    }

    public function thirdParty(): BelongsTo
    {
        return $this->belongsTo(ThirdParty::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Scopes
    public function scopeDraft($q)      { return $q->where('status', 'DRAFT'); }
    public function scopeValidated($q)  { return $q->where('status', 'VALIDATED'); }
    public function scopePaid($q)       { return $q->where('status', 'PAID'); }
    public function scopeOverdue($q)    { return $q->where('status', 'OVERDUE'); }
    public function scopeCancelled($q)  { return $q->where('status', 'CANCELLED'); }

    public function scopeSearch($q, string $term)
    {
        return $q->where(function ($sq) use ($term) {
            $sq->where('ref', 'ilike', "%{$term}%")
               ->orWhere('supplier_name', 'ilike', "%{$term}%");
        });
    }

    public function getBalanceAttribute(): float
    {
        return (float) $this->total_amount - (float) $this->amount_paid;
    }
}
