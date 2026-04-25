<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QboAccountMapping extends Model
{
    protected $table = 'qbo_account_mappings';

    protected $fillable = [
        'mapping_key', 'qbo_account_id', 'qbo_account_name', 'mapped_by',
    ];

    public function mapper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mapped_by');
    }

    public static function for(string $key): ?self
    {
        return self::where('mapping_key', $key)->first();
    }
}
