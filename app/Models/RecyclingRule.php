<?php

namespace App\Models;

use App\Domain\Lead\Enums\LeadOutcome;
use Illuminate\Database\Eloquent\Model;

class RecyclingRule extends Model
{
    protected $fillable = [
        'outcome',
        'cooldown_hours',
        'max_cycles',
        'next_action',
        'is_active',
    ];

    protected $casts = [
        'outcome' => LeadOutcome::class,
        'is_active' => 'boolean',
    ];

    public static function forOutcome(LeadOutcome $outcome): ?self
    {
        return self::where('outcome', $outcome->value)
            ->where('is_active', true)
            ->first();
    }

    public function shouldExhaust(): bool
    {
        return $this->next_action === 'EXHAUST';
    }
}
