<?php

declare(strict_types=1);

namespace App\Domain\Shop\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartTemplate extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'items',
        'courier_code',
        'shipping_fee',
        'discount_amount',
        'tax_rate',
        'remarks',
        'is_shared',
    ];

    protected $casts = [
        'items'           => 'array',
        'shipping_fee'    => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_rate'        => 'decimal:2',
        'is_shared'       => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeSharedOrOwned($query, int $userId)
    {
        return $query->where(function ($q) use ($userId) {
            $q->where('user_id', $userId)->orWhere('is_shared', true);
        });
    }
}
