<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CapexAsset extends Model
{
    use SoftDeletes;

    public const CATEGORIES = [
        'EQUIPMENT' => 'Equipment',
        'FURNITURE' => 'Furniture & Fixtures',
        'VEHICLE' => 'Vehicle',
        'IT_HARDWARE' => 'IT Hardware',
        'LEASEHOLD_IMPROVEMENT' => 'Leasehold Improvement',
        'OTHER' => 'Other',
    ];

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_DISPOSED = 'DISPOSED';

    public const STATUS_UNDER_REPAIR = 'UNDER_REPAIR';

    protected $table = 'capex_assets';

    protected $fillable = [
        'asset_code', 'name', 'description', 'category',
        'depreciation_years', 'purchase_date', 'acquisition_cost', 'salvage_value',
        'current_book_value', 'status',
        'warehouse_id', 'assigned_to', 'department',
        'uom_id', 'quantity',
        'disposed_at', 'disposal_reason', 'disposal_value',
        'created_by',
    ];

    protected $casts = [
        'acquisition_cost' => 'decimal:4',
        'salvage_value' => 'decimal:4',
        'current_book_value' => 'decimal:4',
        'disposal_value' => 'decimal:4',
        'depreciation_years' => 'integer',
        'quantity' => 'integer',
        'purchase_date' => 'date',
        'disposed_at' => 'datetime',
    ];

    public function depreciationSchedule(): HasMany
    {
        return $this->hasMany(CapexDepreciationSchedule::class)->orderBy('year');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(CapexAssetAssignment::class)->latest('assigned_at');
    }

    public function currentAssignment(): HasMany
    {
        return $this->hasMany(CapexAssetAssignment::class)->whereNull('returned_at')->latest('assigned_at');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class);
    }

    public function annualDepreciation(): float
    {
        return $this->depreciation_years > 0
            ? (float) (($this->acquisition_cost - $this->salvage_value) / $this->depreciation_years)
            : 0.0;
    }
}
