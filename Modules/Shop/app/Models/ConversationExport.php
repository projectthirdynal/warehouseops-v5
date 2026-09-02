<?php

declare(strict_types=1);

namespace Modules\Shop\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationExport extends Model
{
    protected $fillable = [
        'export_number',
        'created_by',
        'status',
        'conversation_count',
        'message_count',
        'file_path',
        'filters',
        'exported_at',
    ];

    protected $casts = [
        'filters' => 'array',
        'exported_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
