<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QboConnection extends Model
{
    protected $table = 'qbo_connections';

    protected $fillable = [
        'realm_id', 'access_token', 'refresh_token', 'expires_at',
        'environment', 'connected_by', 'connected_at', 'is_active',
    ];

    protected $casts = [
        'access_token'  => 'encrypted',
        'refresh_token' => 'encrypted',
        'expires_at'    => 'datetime',
        'connected_at'  => 'datetime',
        'is_active'     => 'boolean',
    ];

    public function connector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    public static function active(): ?self
    {
        return self::where('is_active', true)->latest()->first();
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function expiresWithinMinutes(int $minutes): bool
    {
        return $this->expires_at !== null && $this->expires_at->lt(now()->addMinutes($minutes));
    }
}
