<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contact extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'third_party_id',
        'first_name',
        'last_name',
        'title',
        'position',
        'department',
        'email',
        'phone',
        'phone_alt',
        'is_primary',
        'notes',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function thirdParty(): BelongsTo
    {
        return $this->belongsTo(ThirdParty::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function getDisplayNameAttribute(): string
    {
        $name = $this->full_name;
        if ($this->position) {
            $name .= " — {$this->position}";
        }
        return $name;
    }
}
