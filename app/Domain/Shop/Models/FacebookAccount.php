<?php

declare(strict_types=1);

namespace App\Domain\Shop\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FacebookAccount extends Model
{
    use HasFactory, SoftDeletes;

    public const CONNECTION_ACTIVE = 'active';
    public const CONNECTION_EXPIRING = 'expiring';
    public const CONNECTION_EXPIRED = 'expired';
    public const CONNECTION_REVOKED = 'revoked';
    public const CONNECTION_PERMISSION_MISSING = 'permission_missing';
    public const CONNECTION_RECONNECT_REQUIRED = 'reconnect_required';
    public const CONNECTION_DISCONNECTED = 'disconnected';

    protected $fillable = [
        'user_id',
        'facebook_user_id',
        'facebook_user_name',
        'email',
        'access_token',
        'token_expires_at',
        'data_access_expires_at',
        'last_validated_at',
        'last_validation_error',
        'connection_status',
        'reconnect_required_at',
        'status',
        'connected_at',
        'metadata',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'token_expires_at' => 'datetime',
        'data_access_expires_at' => 'datetime',
        'last_validated_at' => 'datetime',
        'reconnect_required_at' => 'datetime',
        'connected_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected $hidden = [
        'access_token',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(FacebookPage::class);
    }
}
