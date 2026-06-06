<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Permission extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'label', 'section', 'description'];

    public function rolePermissions(): HasMany
    {
        return $this->hasMany(RolePermission::class);
    }

    public static function sections(): array
    {
        return static::query()
            ->select('section')
            ->distinct()
            ->pluck('section')
            ->toArray();
    }
}
