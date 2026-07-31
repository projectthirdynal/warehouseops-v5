<?php

declare(strict_types=1);

namespace App\Domain\Waybill\Models;

use App\Models\User;
use App\Models\Waybill;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class DeliveryProof extends Model
{
    protected $table = 'delivery_proofs';

    protected $fillable = [
        'waybill_id',
        'type',
        'file_path',
        'original_filename',
        'mime_type',
        'file_size',
        'source',
        'courier_code',
        'metadata',
        'uploaded_by',
    ];

    protected $casts = [
        'metadata' => 'array',
        'file_size' => 'integer',
    ];

    public function waybill(): BelongsTo
    {
        return $this->belongsTo(Waybill::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function scopePhotos($query)
    {
        return $query->where('type', 'photo');
    }

    public function scopeSignatures($query)
    {
        return $query->where('type', 'signature');
    }

    public function scopeFromCourier($query)
    {
        return $query->where('source', 'courier_callback');
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk('public')->url($this->file_path);
    }
}
