<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    protected $fillable = ['key', 'value', 'group', 'type', 'description'];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = self::where('key', $key)->first();
        if (! $setting) {
            return $default;
        }

        return match ($setting->type) {
            'int' => (int) $setting->value,
            'bool' => (bool) $setting->value,
            'json' => json_decode($setting->value, true),
            default => $setting->value,
        };
    }

    public static function set(string $key, mixed $value, string $group = 'general', string $type = 'string', ?string $description = null): void
    {
        $storedValue = match ($type) {
            'json' => json_encode($value),
            'bool' => $value ? '1' : '0',
            default => (string) $value,
        };

        self::updateOrCreate(
            ['key' => $key],
            [
                'value' => $storedValue,
                'group' => $group,
                'type' => $type,
                'description' => $description,
            ]
        );
    }

    public static function getGroup(string $group): array
    {
        return self::where('group', $group)
            ->get()
            ->mapWithKeys(fn ($s) => [$s->key => match ($s->type) {
                'int' => (int) $s->value,
                'bool' => (bool) $s->value,
                'json' => json_decode($s->value, true),
                default => $s->value,
            }])
            ->toArray();
    }
}
