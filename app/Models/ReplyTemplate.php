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
        'approval_status',
        'approved_by',
        'approved_at',
        'rejection_reason',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'usage_count' => 'integer',
        'variables' => 'array',
        'allowed_roles' => 'array',
        'approved_at' => 'datetime',
    ];

    public const APPROVAL_PENDING = 'pending';
    public const APPROVAL_APPROVED = 'approved';
    public const APPROVAL_REJECTED = 'rejected';

    public const APPROVAL_STATUSES = [
        self::APPROVAL_PENDING,
        self::APPROVAL_APPROVED,
        self::APPROVAL_REJECTED,
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

    public function versions(): HasMany
    {
        return $this->hasMany(ReplyTemplateVersion::class)->orderByDesc('version_number');
    }

    public function sharedPages(): BelongsToMany
    {
        return $this->belongsToMany(FacebookPage::class, 'reply_template_shares')->withTimestamps();
    }

    public function isSharedWithPage(int $pageId): bool
    {
        return $this->sharedPages()->where('facebook_page_id', $pageId)->exists();
    }

    public function isAvailableForPage(?int $pageId): bool
    {
        if ($this->facebook_page_id === null) {
            return true;
        }

        if ($this->facebook_page_id === $pageId) {
            return true;
        }

        if ($pageId !== null && $this->isSharedWithPage($pageId)) {
            return true;
        }

        return false;
    }

    public function isAccessibleBy(string $role): bool
    {
        if (empty($this->allowed_roles)) {
            return true;
        }

        return in_array($role, $this->allowed_roles, true);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isApproved(): bool
    {
        return $this->approval_status === null || $this->approval_status === self::APPROVAL_APPROVED;
    }

    public function isPending(): bool
    {
        return $this->approval_status === self::APPROVAL_PENDING;
    }

    public function isRejected(): bool
    {
        return $this->approval_status === self::APPROVAL_REJECTED;
    }
}
