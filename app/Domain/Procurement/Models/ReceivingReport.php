<?php

declare(strict_types=1);

namespace App\Domain\Procurement\Models;

use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Models\WarehouseLocation;
use App\Domain\Procurement\Enums\GrnStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReceivingReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'grn_number', 'po_id', 'warehouse_id', 'location_id',
        'received_by', 'received_at', 'exchange_rate', 'exchange_rate_date',
        'status', 'notes', 'discrepancy_notes', 'confirmed_at',
    ];

    protected $casts = [
        'status'             => GrnStatus::class,
        'received_at'        => 'datetime',
        'confirmed_at'       => 'datetime',
        'exchange_rate'      => 'decimal:6',
        'exchange_rate_date' => 'date',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(ReceivingReportItem::class, 'grn_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'location_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public static function generateNumber(): string
    {
        $date = now()->format('Ymd');
        $seq  = self::whereDate('created_at', now()->toDateString())->count() + 1;
        return 'GRN-' . $date . '-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
