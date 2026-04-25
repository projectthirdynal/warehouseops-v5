<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supply extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'supplies';

    protected $fillable = [
        'sku', 'name', 'category', 'uom_id', 'cost_price',
        'min_stock_level', 'reorder_point', 'description', 'is_active',
    ];

    protected $casts = [
        'cost_price'      => 'decimal:4',
        'min_stock_level' => 'integer',
        'reorder_point'   => 'integer',
        'is_active'       => 'boolean',
    ];

    public function stocks(): HasMany
    {
        return $this->hasMany(SupplyStock::class);
    }

    public function totalStock(): int
    {
        return (int) $this->stocks()->sum('current_stock');
    }
}
