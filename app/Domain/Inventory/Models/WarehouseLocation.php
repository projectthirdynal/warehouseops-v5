<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Product\Models\ProductStock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WarehouseLocation extends Model
{
    protected $fillable = [
        'warehouse_id', 'code', 'name', 'type', 'capacity', 'is_active',
        'row_index', 'col_index', 'zone_color',
    ];

    protected $casts = [
        'capacity' => 'integer',
        'is_active' => 'boolean',
        'row_index' => 'integer',
        'col_index' => 'integer',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function productStocks(): HasMany
    {
        return $this->hasMany(ProductStock::class, 'location_id');
    }

    public function supplyStocks(): HasMany
    {
        return $this->hasMany(SupplyStock::class, 'location_id');
    }
}
