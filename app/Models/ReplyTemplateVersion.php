<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shop\Models\FacebookPage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReplyTemplateVersion extends Model
{
    protected $table = 'reply_template_versions';

    protected $fillable = [
        'reply_template_id',
        'edited_by',
        'version_number',
        'title',
        'content',
        'variables',
        'category',
        'intent',
        'allowed_roles',
        'shortcut',
        'facebook_page_id',
        'is_active',
        'shared_page_ids',
        'change_summary',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'variables' => 'array',
        'allowed_roles' => 'array',
        'shared_page_ids' => 'array',
    ];

    public function replyTemplate(): BelongsTo
    {
        return $this->belongsTo(ReplyTemplate::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    public function facebookPage(): BelongsTo
    {
        return $this->belongsTo(FacebookPage::class);
    }
}
