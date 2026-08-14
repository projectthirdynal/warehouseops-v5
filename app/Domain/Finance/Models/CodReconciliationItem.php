<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Order\Models\Order;
use App\Domain\Waybill\Models\Waybill;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CodReconciliationItem extends Model
{
    protected $fillable = [
        'cod_settlement_id',
        'order_id',
        'waybill_id',
        'courier_code',
        'order_number',
        'waybill_number',
        'expected_cod',
        'remitted_cod',
        'variance',
        'match_status',
        'match_type',
        'matched_at',
        'notes',
    ];

    protected $casts = [
        'expected_cod' => 'decimal:2',
        'remitted_cod' => 'decimal:2',
        'variance' => 'decimal:2',
        'matched_at' => 'datetime',
    ];

    public const MATCH_STATUS_MATCHED = 'MATCHED';

    public const MATCH_STATUS_UNMATCHED = 'UNMATCHED';

    public const MATCH_STATUS_MANUAL_MATCH = 'MANUAL_MATCH';

    public const MATCH_STATUS_MISMATCH = 'MISMATCH';

    public const MATCH_TYPE_AUTO = 'AUTO';

    public const MATCH_TYPE_MANUAL = 'MANUAL';

    // Relationships

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(CodSettlement::class, 'cod_settlement_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function waybill(): BelongsTo
    {
        return $this->belongsTo(Waybill::class);
    }

    // Scopes

    public function scopeMatched($query)
    {
        return $query->whereIn('match_status', [self::MATCH_STATUS_MATCHED, self::MATCH_STATUS_MANUAL_MATCH]);
    }

    public function scopeUnmatched($query)
    {
        return $query->where('match_status', self::MATCH_STATUS_UNMATCHED);
    }

    public function scopeMismatch($query)
    {
        return $query->where('match_status', self::MATCH_STATUS_MISMATCH);
    }
}
