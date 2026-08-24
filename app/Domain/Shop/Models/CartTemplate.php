<?php

declare(strict_types=1);

namespace App\Domain\Shop\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartTemplate extends Model
{
    public const ALLOWED_ROLES = ['superadmin', 'admin', 'supervisor', 'agent', 'encoder'];

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

    public function isOwnedBy(int $userId): bool
    {
        return $this->user_id === $userId;
    }

    public function isAccessibleBy(int $userId, ?string $role = null): bool
    {
        if ($this->isOwnedBy($userId)) {
            return true;
        }

        if (! $this->is_shared) {
            return false;
        }

        if (empty($this->allowed_roles)) {
            return true;
        }

        return $role !== null && in_array($role, $this->allowed_roles, true);
    }

    public function itemsCount(): int
    {
        return count($this->items);
    }

    public function recordUsage(): void
    {
        $this->forceFill(['last_used_at' => now()])->save();
    }

    public function cloneFor(int $userId, ?string $name = null): self
    {
        return self::query()->create([
            'user_id' => $userId,
            'name' => $name ?? "{$this->name} (Copy)",
            'items' => $this->items,
            'courier_code' => $this->courier_code,
            'shipping_fee' => $this->shipping_fee ?? 0,
            'discount_amount' => $this->discount_amount ?? 0,
            'tax_rate' => $this->tax_rate ?? 0,
            'remarks' => $this->remarks,
            'is_shared' => false,
            'allowed_roles' => null,
            'cloned_from' => $this->id,
        ]);
    }
}
