<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shop\Models\FacebookPage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ReplyTemplate extends Model
{
    use SoftDeletes;

    protected $table = 'reply_templates';

    protected $fillable = [
        'title',
        'content',
        'variables',
        'category',
        'intent',
        'allowed_roles',
        'shortcut',
        'facebook_page_id',
        'created_by',
        'is_active',
        'usage_count',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'usage_count' => 'integer',
        'variables' => 'array',
        'allowed_roles' => 'array',
    ];

    public function facebookPage(): BelongsTo
    {
        return $this->belongsTo(FacebookPage::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function favoritedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'reply_template_favorites')->withTimestamps();
    }

    public function usages(): HasMany
    {
        return $this->hasMany(ReplyTemplateUsage::class);
    }

    public function isAccessibleBy(string $role): bool
    {
        if (empty($this->allowed_roles)) {
            return true;
        }

        return in_array($role, $this->allowed_roles, true);
    }
}
