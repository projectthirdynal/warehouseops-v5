<?php

declare(strict_types=1);

namespace Modules\Shop\Models;

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
        'allowed_roles',
        'cloned_from',
        'last_used_at',
    ];

    protected $casts = [
        'items' => 'array',
        'shipping_fee' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'is_shared' => 'boolean',
        'allowed_roles' => 'array',
        'last_used_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function clonedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'cloned_from');
    }

    public function scopeSharedOrOwned($query, int $userId)
    {
        return $query->where(function ($q) use ($userId) {
            $q->where('user_id', $userId)->orWhere('is_shared', true);
        });
    }

    public function scopeAccessibleTo($query, int $userId, ?string $role = null)
    {
        return $query->where(function ($q) use ($userId, $role) {
            $q->where('user_id', $userId)
                ->orWhere(function ($sq) use ($role) {
                    $sq->where('is_shared', true)
                        ->where(function ($rq) use ($role) {
                            $rq->whereNull('allowed_roles')
                                ->orWhereJsonContains('allowed_roles', $role);
                        });
                });
        });
    }
}
