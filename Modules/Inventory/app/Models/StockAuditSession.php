<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockAuditSession extends Model
{
    public const STATUS_OPEN = 'OPEN';

    public const STATUS_COUNTING = 'COUNTING';

    public const STATUS_FINALIZED = 'FINALIZED';

    public const STATUS_CANCELLED = 'CANCELLED';

    protected $fillable = [
        'warehouse_id',
        'name',
        'status',
        'started_by',
        'finalized_by',
        'started_at',
        'finalized_at',
        'notes',
        'auto_generated',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finalized_at' => 'datetime',
        'auto_generated' => 'boolean',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockAuditItem::class, 'session_id');
    }
}
