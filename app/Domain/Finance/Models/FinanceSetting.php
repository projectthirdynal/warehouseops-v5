<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceSetting extends Model
{
    protected $fillable = [
        'key', 'value', 'locked_at', 'locked_by', 'locked_trigger_reference',
    ];

    protected $casts = [
        'value'     => 'array',
        'locked_at' => 'datetime',
    ];

    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public static function getValue(string $key): ?array
    {
        $row = self::where('key', $key)->first();
        return $row?->value;
    }

    public static function lock(string $key, int $userId, string $reference): void
    {
        $row = self::where('key', $key)->first();
        if ($row && $row->locked_at === null) {
            $row->locked_at                 = now();
            $row->locked_by                 = $userId;
            $row->locked_trigger_reference  = $reference;
            $row->save();
        }
    }

    public static function isLocked(string $key): bool
    {
        return self::where('key', $key)->whereNotNull('locked_at')->exists();
    }
}
