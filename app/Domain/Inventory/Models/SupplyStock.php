<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplyStock extends Model
{
    protected $fillable = [
        'supply_id', 'warehouse_id', 'location_id',
        'current_stock', 'reserved_stock', 'reorder_point', 'last_restock_at',
    ];

    protected $casts = [
        'current_stock'   => 'integer',
        'reserved_stock'  => 'integer',
        'reorder_point'   => 'integer',
        'last_restock_at' => 'datetime',
    ];

    protected $appends = ['available_stock'];

    public function getAvailableStockAttribute(): int
    {
        return max(0, $this->current_stock - $this->reserved_stock);
    }

    public function supply(): BelongsTo
    {
        return $this->belongsTo(Supply::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
