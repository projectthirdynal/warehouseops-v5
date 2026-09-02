<?php

declare(strict_types=1);

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeRate extends Model
{
    protected $fillable = [
        'from_currency', 'to_currency', 'rate', 'rate_date', 'source',
    ];

    protected $casts = [
        'rate' => 'decimal:6',
        'rate_date' => 'date',
    ];

    public function fromCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'from_currency', 'code');
    }

    public function toCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'to_currency', 'code');
    }

    public function scopeLatestForPair($query, string $from, string $to)
    {
        return $query->where('from_currency', $from)
            ->where('to_currency', $to)
            ->orderByDesc('rate_date')
            ->limit(1);
    }
}
