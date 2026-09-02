<?php

declare(strict_types=1);

namespace App\Models;

use Modules\Shop\Models\Conversation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReplyTemplateUsage extends Model
{
    public $timestamps = false;

    protected $table = 'reply_template_usage';

    protected $fillable = [
        'reply_template_id',
        'user_id',
        'conversation_id',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function replyTemplate(): BelongsTo
    {
        return $this->belongsTo(ReplyTemplate::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
