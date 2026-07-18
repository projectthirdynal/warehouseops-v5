<?php

declare(strict_types=1);

namespace App\Domain\Shop\Models;

use App\Domain\Order\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AddressCorrectionHistory extends Model
{
    protected $table = 'address_correction_history';

    protected $fillable = [
        'order_id',
        'user_id',
        'before',
        'after',
        'confidence_before',
        'confidence_after',
        'action',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'confidence_before' => 'float',
        'confidence_after' => 'float',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
