<?php

declare(strict_types=1);

namespace App\Domain\Procurement\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'code', 'contact_person', 'email', 'phone', 'address',
        'payment_terms', 'lead_time_days', 'is_active', 'qbo_vendor_id',
    ];

    protected $casts = [
        'is_active'      => 'boolean',
        'lead_time_days' => 'integer',
    ];

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
