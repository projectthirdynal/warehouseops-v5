<?php

declare(strict_types=1);

namespace Modules\Shop\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourierCsvTemplate extends Model
{
    protected $table = 'courier_csv_templates';

    protected $fillable = [
        'name',
        'courier_code',
        'columns',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'columns' => 'array',
        'is_active' => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
