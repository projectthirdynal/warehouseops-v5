<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CapexDepreciationJournal extends Model
{
    protected $table = 'capex_depreciation_journal';

    protected $fillable = [
        'capex_asset_id',
        'depreciation_schedule_id',
        'year',
        'month',
        'posting_date',
        'depreciation_amount',
        'accumulated_depreciation',
        'book_value_after',
        'debit_account',
        'credit_account',
        'reference',
        'notes',
        'is_posted',
        'posted_at',
        'posted_by',
    ];

    protected $casts = [
        'depreciation_amount' => 'decimal:4',
        'accumulated_depreciation' => 'decimal:4',
        'book_value_after' => 'decimal:4',
        'posting_date' => 'date',
        'is_posted' => 'boolean',
        'posted_at' => 'datetime',
        'year' => 'integer',
        'month' => 'integer',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(CapexAsset::class, 'capex_asset_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(CapexDepreciationSchedule::class, 'depreciation_schedule_id');
    }

    public function postedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
