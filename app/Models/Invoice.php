<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Invoice extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'ref', 'type', 'status',
        'third_party_id', 'client_name', 'client_email', 'client_phone', 'client_address',
        'order_id', 'quotation_id',
        'date_invoice', 'date_due', 'date_sent',
        'payment_terms', 'currency',
        'subtotal', 'discount_amount', 'tax_rate', 'tax_amount', 'shipping_amount',
        'total_amount', 'amount_paid', 'amount_due',
        'notes', 'terms', 'footer',
        'cancel_reason', 'cancelled_at',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'date_invoice'    => 'date',
        'date_due'        => 'date',
        'date_sent'       => 'date',
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
        $count = DB::transaction(function () use ($year) {
            return self::withTrashed()->whereYear('created_at', $year)->lockForUpdate()->count() + 1;
        });
        return sprintf('INV-%s-%05d', $year, $count);
    }

    public function thirdParty(): BelongsTo
    {
        return $this->belongsTo(ThirdParty::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Scopes
    public function scopeDraft($q)      { return $q->where('status', 'DRAFT'); }
    public function scopeValidated($q)  { return $q->where('status', 'VALIDATED'); }
    public function scopeSent($q)       { return $q->where('status', 'SENT'); }
    public function scopePartial($q)    { return $q->where('status', 'PARTIAL'); }
    public function scopePaid($q)       { return $q->where('status', 'PAID'); }
    public function scopeOverdue($q)    { return $q->where('status', 'OVERDUE'); }
    public function scopeCancelled($q)  { return $q->where('status', 'CANCELLED'); }
    public function scopeStandard($q)   { return $q->where('type', 'standard'); }
    public function scopeCreditNote($q) { return $q->where('type', 'credit_note'); }

    public function scopeSearch($q, string $term)
    {
        $like = '%' . mb_strtolower($term) . '%';

        return $q->where(function ($sq) use ($like) {
            $sq->whereRaw('LOWER(ref) LIKE ?', [$like])
               ->orWhereRaw('LOWER(client_name) LIKE ?', [$like])
               ->orWhereRaw('LOWER(client_email) LIKE ?', [$like]);
        });
    }

    // Accessors
    public function getBalanceAttribute(): float
    {
        return (float) $this->total_amount - (float) $this->amount_paid;
    }
}
