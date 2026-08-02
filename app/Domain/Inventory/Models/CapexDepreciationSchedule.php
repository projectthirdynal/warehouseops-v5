<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CapexDepreciationSchedule extends Model
{
    protected $table = 'capex_depreciation_schedule';

    protected $fillable = [
        'capex_asset_id', 'year', 'fiscal_year',
        'opening_book_value', 'depreciation_amount', 'closing_book_value',
        'depreciation_date', 'is_posted', 'posted_at', 'posted_by',
    ];

    protected $casts = [
        'opening_book_value' => 'decimal:4',
        'depreciation_amount' => 'decimal:4',
        'closing_book_value' => 'decimal:4',
        'depreciation_date' => 'date',
        'is_posted' => 'boolean',
        'posted_at' => 'datetime',
        'year' => 'integer',
        'fiscal_year' => 'integer',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(CapexAsset::class, 'capex_asset_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
