<?php

declare(strict_types=1);

namespace App\Domain\Lead\Models;

use App\Domain\Customer\Models\Customer;
use App\Domain\Lead\Enums\LeadStatus;
use App\Domain\Lead\Enums\PoolStatus;
use App\Domain\Lead\Enums\SalesStatus;
use App\Domain\Waybill\Models\Waybill;
use App\Models\LeadCycle;
use App\Models\User;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;

class Lead extends Model
{
    use HasFactory, SoftDeletes;

    protected static function newFactory(): LeadFactory
    {
        return LeadFactory::new();
    }

    protected $fillable = [
        'customer_id',
        'recycling_pool_id',
        'name',
        'phone',
        'address',
        'city',
        'state',
        'barangay',
        'street',
        'postal_code',
        'status',
        'sales_status',
        'source',
        'assigned_to',
        'uploaded_by',
        'original_agent_id',
        'last_called_at',
        'call_attempts',
        'notes',
        'product_name',
        'product_brand',
        'previous_item',
        'amount',
        'assigned_at',
        'total_cycles',
        'max_cycles',
        'is_exhausted',
        'quality_score',
        'last_scored_at',
        'current_qa_level',
        'qa_required',
        'pool_status',
        'cooldown_until',
    ];

    protected $casts = [
        'status' => LeadStatus::class,
        'sales_status' => SalesStatus::class,
        'last_called_at' => 'datetime',
        'assigned_at' => 'datetime',
        'last_scored_at' => 'datetime',
        'call_attempts' => 'integer',
        'total_cycles' => 'integer',
        'max_cycles' => 'integer',
        'is_exhausted' => 'boolean',
        'quality_score' => 'integer',
        'current_qa_level' => 'integer',
        'qa_required' => 'boolean',
        'amount' => 'decimal:2',
        'pool_status' => PoolStatus::class,
        'cooldown_until' => 'datetime',
    ];

    protected $attributes = [
        'status' => LeadStatus::NEW,
        'sales_status' => SalesStatus::NEW,
        'call_attempts' => 0,
        'total_cycles' => 0,
        'max_cycles' => 3,
        'is_exhausted' => false,
        'current_qa_level' => 1,
        'qa_required' => true,
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function originalAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'original_agent_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function waybills(): HasMany
    {
        return $this->hasMany(Waybill::class);
    }

    public function cycles(): HasMany
    {
        return $this->hasMany(LeadCycle::class)->orderByDesc('cycle_number');
    }

    public function activeCycle(): HasOne
    {
        return $this->hasOne(LeadCycle::class)->where('status', 'ACTIVE')->latest();
    }

    public function logs(): HasMany
    {
        return $this->hasMany(LeadLog::class)->orderByDesc('created_at');
    }

    public function qaReviews(): HasMany
    {
        return $this->hasMany(QaReview::class)->orderByDesc('created_at');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeNew($query)
    {
        return $query->where('status', LeadStatus::NEW);
    }

    public function scopeUnassigned($query)
    {
        return $query->whereNull('assigned_to');
    }

    public function scopeAssignedTo($query, int $userId)
    {
        return $query->where('assigned_to', $userId);
    }

    public function scopePendingQa($query, int $level = 1)
    {
        return $query->where('sales_status', SalesStatus::QA_PENDING)
            ->where('current_qa_level', $level);
    }

    public function scopeNotExhausted($query)
    {
        return $query->where('is_exhausted', false);
    }

    public function scopeRecyclable($query)
    {
        return $query->where('is_exhausted', false)
            ->whereDoesntHave('cycles', fn ($q) => $q->where('status', 'ACTIVE'));
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('pool_status', PoolStatus::AVAILABLE);
    }

    public function scopeAssigned(Builder $query): Builder
    {
        return $query->where('pool_status', PoolStatus::ASSIGNED);
    }

    public function scopeInCooldown(Builder $query): Builder
    {
        return $query->where('pool_status', PoolStatus::COOLDOWN);
    }

    public function scopeExhausted(Builder $query): Builder
    {
        return $query->where('pool_status', PoolStatus::EXHAUSTED);
    }

    public function scopeCooldownExpired(Builder $query): Builder
    {
        return $query->where('pool_status', PoolStatus::COOLDOWN)
            ->where('cooldown_until', '<=', now());
    }

    // -------------------------------------------------------------------------
    // Accessors
    // -------------------------------------------------------------------------

    public function getFullAddressAttribute(): string
    {
        $parts = array_filter([
            $this->street,
            $this->barangay,
            $this->city,
            $this->state,
            $this->postal_code,
        ]);

        return implode(', ', $parts);
    }

    public function getHistoryStatsAttribute(): ?array
    {
        $waybills = $this->relationLoaded('waybills')
            ? $this->waybills
            : $this->waybills()->get();

        $total = $waybills->count();
        if ($total === 0) {
            return null;
        }

        $delivered = $waybills->where('status', 'DELIVERED')->count();
        $returned = $waybills->where('status', 'RETURNED')->count();
        $rate = round(($delivered / $total) * 100);

        return [
            'total' => $total,
            'delivered' => $delivered,
            'returned' => $returned,
            'rate' => $rate,
            'risk' => $rate >= 80 ? 'low' : ($rate >= 50 ? 'medium' : 'high'),
        ];
    }

    // -------------------------------------------------------------------------
    // Methods
    // -------------------------------------------------------------------------

    public function canRecycle(): bool|string
    {
        if ($this->is_exhausted) {
            return 'Lead has exceeded maximum recycle attempts.';
        }

        if ($this->activeCycle) {
            return 'Lead is currently active in a cycle.';
        }

        $activeWaybill = $this->waybills()
            ->whereNotIn('status', ['DELIVERED', 'CANCELLED', 'RETURNED'])
            ->exists();

        if ($activeWaybill) {
            return 'Lead has an active waybill in transit.';
        }

        $lastCycle = $this->cycles()->whereNotNull('closed_at')->first();
        if ($lastCycle && $lastCycle->closed_at->diffInHours(now()) < 12) {
            return 'Lead is in cooldown period.';
        }

        return true;
    }

    public function incrementCycleCount(): void
    {
        $this->total_cycles++;

        if ($this->total_cycles >= $this->max_cycles) {
            $this->is_exhausted = true;
        }

        $this->save();
    }

    public function assignTo(User $agent): void
    {
        $this->update([
            'assigned_to' => $agent->id,
            'assigned_at' => now(),
        ]);
    }
}
