<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class FinancialTransaction extends Model
{
    protected $fillable = [
        'type',
        'amount',
        'reference_type',
        'reference_id',
        'description',
        'recorded_by',
        'transaction_date',
    ];

    protected $casts = [
        'amount'           => 'decimal:2',
        'transaction_date' => 'date',
    ];

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
