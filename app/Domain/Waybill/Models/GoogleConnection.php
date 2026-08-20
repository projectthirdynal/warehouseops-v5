<?php

declare(strict_types=1);

namespace App\Domain\Waybill\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class GoogleConnection extends Model
{
    protected $table = 'google_connections';

    protected $fillable = [
        'google_user_id',
        'email',
        'access_token',
        'refresh_token',
        'expires_at',
        'connected_by',
        'connected_at',
        'is_active',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'expires_at' => 'datetime',
        'connected_at' => 'datetime',
        'is_active' => 'boolean',
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

    public function getDecryptedAccessToken(): string
    {
        return Crypt::decryptString($this->access_token);
    }

    public function getDecryptedRefreshToken(): string
    {
        return Crypt::decryptString($this->refresh_token);
    }
}
