<?php

namespace App\Models;

use App\Domain\Shop\Models\AgentBadge;
use App\Domain\Shop\Models\AgentMilestone;
use App\Domain\Shop\Models\AgentStreak;
use App\Domain\Shop\Models\Conversation;
use App\Domain\Shop\Models\FacebookPage;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'email_verified_at',
        'password',
        'role',
        'is_active',
        'phone',
        'last_login_at',
        'theme',
        'language',
        'timezone',
        'birthday',
        'hire_date',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
        'birthday' => 'date',
        'hire_date' => 'date',
    ];

    public function agentProfile(): HasOne
    {
        return $this->hasOne(AgentProfile::class);
    }

    public function assignedLeads(): HasMany
    {
        return $this->hasMany(Lead::class, 'assigned_to');
    }

    public function assignedCycles(): HasMany
    {
        return $this->hasMany(LeadCycle::class, 'assigned_agent_id');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'assigned_agent_id');
    }

    public function favoritePages(): BelongsToMany
    {
        return $this->belongsToMany(FacebookPage::class, 'page_favorites')->withTimestamps();
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, ['superadmin', 'admin']);
    }

    public function isAgent(): bool
    {
        return $this->role === 'agent';
    }

    public function isSupervisor(): bool
    {
        return in_array($this->role, ['supervisor', 'admin', 'superadmin']);
    }

    public function agentStatus(): string
    {
        $profile = $this->agentProfile;

        if (! $profile || ! $profile->is_available) {
            return 'offline';
        }

        $lastSeen = $profile->last_seen_at;

        if ($lastSeen && $lastSeen->gt(now()->subMinutes(5))) {
            return 'online';
        }

        return 'away';
    }

    public function favoriteTemplates(): BelongsToMany
    {
        return $this->belongsToMany(ReplyTemplate::class, 'reply_template_favorites')->withTimestamps();
    }

    public function badges(): HasMany
    {
        return $this->hasMany(AgentBadge::class);
    }

    public function streaks(): HasMany
    {
        return $this->hasMany(AgentStreak::class);
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(AgentMilestone::class);
    }

    public function coachingNotes(): HasMany
    {
        return $this->hasMany(CoachingNote::class, 'agent_id');
    }

    public function authoredCoachingNotes(): HasMany
    {
        return $this->hasMany(CoachingNote::class, 'author_id');
    }
}
