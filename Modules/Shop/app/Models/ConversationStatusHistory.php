<?php

declare(strict_types=1);

namespace Modules\Shop\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationStatusHistory extends Model
{
    protected $table = 'conversation_status_histories';

    protected $fillable = [
        'conversation_id',
        'from_status',
        'to_status',
        'changed_by_id',
        'changed_by_role',
        'reason',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_id');
    }
}
