<?php

declare(strict_types=1);

namespace Modules\Shop\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentMilestone extends Model
{
    protected $fillable = [
        'user_id',
        'milestone_id',
        'current_value',
        'completed_at',
    ];

    protected $casts = [
        'current_value' => 'integer',
        'completed_at' => 'datetime',
    ];

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
