<?php

declare(strict_types=1);

namespace App\Domain\Procurement\Models;

use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Procurement\Enums\PoStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrder extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'po_number', 'pr_id', 'supplier_id', 'warehouse_id',
        'payment_terms', 'expected_delivery_date', 'status',
        'currency_code', 'exchange_rate', 'exchange_rate_date',
        'subtotal', 'tax_amount', 'total_amount',
        'approved_by', 'approved_at', 'sent_at', 'qbo_po_id',
        'notes', 'created_by',
    ];

    protected $casts = [
        'status'                 => PoStatus::class,
        'expected_delivery_date' => 'date',
        'exchange_rate'          => 'decimal:6',
        'exchange_rate_date'     => 'date',
        'subtotal'               => 'decimal:2',
        'tax_amount'             => 'decimal:2',
        'total_amount'           => 'decimal:2',
        'approved_at'            => 'datetime',
        'sent_at'                => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class, 'po_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'pr_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function receivingReports(): HasMany
    {
        return $this->hasMany(ReceivingReport::class, 'po_id');
    }

    public static function generateNumber(): string
    {
        $date = now()->format('Ymd');
        $seq  = self::whereDate('created_at', now()->toDateString())->count() + 1;
        return 'PO-' . $date . '-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    public function recalculateTotals(): void
    {
        $items = $this->items()->get();
        $subtotal = $items->sum(fn ($i) => (float) $i->quantity_ordered * (float) $i->unit_price);
        $tax      = $items->sum(fn ($i) => (float) $i->line_total - ((float) $i->quantity_ordered * (float) $i->unit_price));
        $this->subtotal     = $subtotal;
        $this->tax_amount   = max(0, $tax);
        $this->total_amount = $subtotal + max(0, $tax);
        $this->save();
    }
}
