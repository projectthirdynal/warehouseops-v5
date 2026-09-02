<?php

declare(strict_types=1);

namespace Modules\Shop\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Orders\Models\Order;

class OrderRemark extends Model
{
    protected $fillable = [
        'order_id',
        'parent_id',
        'conversation_id',
        'user_id',
        'type',
        'visibility',
        'body',
        'metadata',
        'mentions',
        'is_pinned',
        'pinned_at',
        'pinned_by',
        'tags',
    ];

    protected $casts = [
        'metadata' => 'array',
        'mentions' => 'array',
        'is_pinned' => 'boolean',
        'pinned_at' => 'datetime',
        'tags' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(OrderRemark::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(OrderRemark::class, 'parent_id')->orderBy('created_at');
    }

    public function pinnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pinned_by');
    }
}
