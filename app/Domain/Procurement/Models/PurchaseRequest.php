<?php

declare(strict_types=1);

namespace App\Domain\Procurement\Models;

use App\Domain\Procurement\Enums\PrStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseRequest extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'pr_number', 'requested_by', 'department', 'reason', 'priority',
        'needed_by_date', 'status', 'approved_by', 'approved_at',
        'rejected_reason', 'estimated_total',
    ];

    protected $casts = [
        'status'          => PrStatus::class,
        'needed_by_date'  => 'date',
        'approved_at'     => 'datetime',
        'estimated_total' => 'decimal:2',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseRequestItem::class, 'pr_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'id', 'pr_id');
    }

    public static function generateNumber(): string
    {
        $date = now()->format('Ymd');
        $seq  = self::whereDate('created_at', now()->toDateString())->count() + 1;
        return 'PR-' . $date . '-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
