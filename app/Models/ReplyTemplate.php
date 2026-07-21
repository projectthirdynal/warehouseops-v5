<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shop\Models\FacebookPage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ReplyTemplate extends Model
{
    use SoftDeletes;

    protected $table = 'reply_templates';

    protected $fillable = [
        'title',
        'content',
        'shortcut',
        'facebook_page_id',
        'created_by',
        'is_active',
        'usage_count',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'usage_count' => 'integer',
    ];

    public function facebookPage(): BelongsTo
    {
        return $this->belongsTo(FacebookPage::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
