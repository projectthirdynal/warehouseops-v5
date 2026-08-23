<?php

declare(strict_types=1);

namespace App\Domain\Promo\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromoPriceRemark extends Model
{
    use HasFactory;

    protected $table = 'promo_price_remarks';

    protected $fillable = [
        'price_key',
        'remarks',
        'imported_by',
        'imported_at',
    ];

    protected $casts = [
        'imported_at' => 'datetime',
    ];

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
