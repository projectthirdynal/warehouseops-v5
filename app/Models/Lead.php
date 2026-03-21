<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'phone',
        'address',
        'city',
        'state',
        'barangay',
        'postal_code',
        'status',
        'sales_status',
        'source',
        'assigned_to',
        'customer_id',
        'product_name',
        'product_brand',
        'product_sku',
        'amount',
        'total_cycles',
        'quality_score',
        'callback_at',
        'callback_notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'callback_at' => 'datetime',
    ];

    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function cycles(): HasMany
    {
        return $this->hasMany(LeadCycle::class);
    }

    public function waybills(): HasMany
    {
        return $this->hasMany(Waybill::class);
    }
}
