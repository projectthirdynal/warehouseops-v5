<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supply extends Model
{
    use HasFactory, SoftDeletes;

    public const SECTION_STOCK = 'STOCK';
    public const SECTION_OPEX  = 'OPEX';

    public const STOCK_CATEGORIES = [
        'RAW_MATERIAL'         => 'Raw Materials',
        'PRODUCTION_MATERIAL'  => 'Production Materials',
        'MERCHANDISE'          => 'Merchandise',
        'RD_SUPPLY'            => 'R&D Supplies',
    ];

    public const OPEX_CATEGORIES = [
        'OFFICE_SUPPLY'     => 'Office Supplies',
        'CLEANING_MATERIAL' => 'Cleaning Materials',
    ];

    public const STATUS_MOVING     = 'MOVING';
    public const STATUS_NON_MOVING = 'NON_MOVING';
    public const STATUS_DEAD       = 'DEAD';

    protected $table = 'supplies';

    protected $fillable = [
        'sku', 'name', 'category',
        'section', 'stock_category', 'opex_category',
        'stock_status', 'stock_status_override', 'delete_reason',
        'uom_id', 'cost_price',
        'min_stock_level', 'reorder_point', 'description', 'is_active',
    ];

    protected $casts = [
        'cost_price'            => 'decimal:4',
        'min_stock_level'       => 'integer',
        'reorder_point'         => 'integer',
        'is_active'             => 'boolean',
        'stock_status_override' => 'boolean',
    ];

    public function stocks(): HasMany
    {
        return $this->hasMany(SupplyStock::class);
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class);
    }

    public function totalStock(): int
    {
        return (int) $this->stocks()->sum('current_stock');
    }

    public function deleteWithReason(string $reason): bool
    {
        $this->delete_reason = $reason;
        $this->save();
        return $this->delete();
    }

    public function scopeSection(Builder $query, string $section): Builder
    {
        return $query->where('section', $section);
    }

    public function scopeStockCategory(Builder $query, string $category): Builder
    {
        return $query->where('stock_category', $category);
    }

    public function scopeMovingStatus(Builder $query, string $status): Builder
    {
        return $query->where('stock_status', $status);
    }
}
