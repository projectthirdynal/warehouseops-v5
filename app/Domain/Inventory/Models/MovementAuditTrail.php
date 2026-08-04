<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class MovementAuditTrail extends Model
{
    use HasFactory;

    public const TYPE_STOCK_IN = 'STOCK_IN';

    public const TYPE_STOCK_OUT = 'STOCK_OUT';

    public const TYPE_ADJUSTMENT = 'ADJUSTMENT';

    public const TYPE_RESERVATION = 'RESERVATION';

    public const TYPE_RELEASE = 'RELEASE';

    public const TYPE_RETURN = 'RETURN';

    public const TYPE_WRITE_OFF = 'WRITE_OFF';

    public const TYPE_TRANSFER = 'TRANSFER';

    protected $fillable = [
        'type',
        'movement_id',
        'movement_type',
        'stockable_type',
        'stockable_id',
        'warehouse_id',
        'quantity',
        'before_quantity',
        'after_quantity',
        'before_reserved',
        'after_reserved',
        'reason_code',
        'reason_notes',
        'reference_type',
        'reference_id',
        'performed_by',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'before_quantity' => 'integer',
        'after_quantity' => 'integer',
        'before_reserved' => 'integer',
        'after_reserved' => 'integer',
        'reference_id' => 'integer',
    ];

    public function movement(): MorphTo
    {
        return $this->morphTo();
    }

    public function stockable(): MorphTo
    {
        return $this->morphTo();
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
