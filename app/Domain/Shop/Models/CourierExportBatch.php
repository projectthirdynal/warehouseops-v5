<?php

declare(strict_types=1);

namespace App\Domain\Shop\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourierExportBatch extends Model
{
    protected $fillable = [
        'batch_number',
        'courier_code',
        'status',
        'created_by',
        'row_count',
        'file_path',
        'exported_at',
        'metadata',
    ];

    protected $casts = [
        'exported_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(CourierExportRow::class);
    }
}
