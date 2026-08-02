<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteSetting extends Model
{
    protected $fillable = ['key', 'value'];

    public $timestamps = false;

    /**
     * Get a setting value with a default fallback.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $record = self::where('key', $key)->first();

        return $record?->value ?? $default;
    }

    /**
     * Set a setting value.
     */
    public static function set(string $key, mixed $value): void
    {
        self::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /**
     * Get all settings as a key-value array.
     */
    public static function allAsArray(): array
    {
        return self::pluck('value', 'key')->toArray();
    }
}
