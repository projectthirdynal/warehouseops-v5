<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class QboSyncQueue extends Model
{
    protected $table = 'qbo_sync_queue';

    protected $fillable = [
        'entity_type', 'entity_id', 'operation', 'idempotency_key',
        'status', 'qbo_id', 'payload', 'error_message', 'attempts', 'synced_at',
    ];

    protected $casts = [
        'payload'   => 'array',
        'attempts'  => 'integer',
        'synced_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $row) {
            if (empty($row->idempotency_key)) {
                $row->idempotency_key = (string) Str::uuid();
            }
        });
    }

    public function scopePending($query)  { return $query->where('status', 'PENDING'); }
    public function scopeFailed($query)   { return $query->where('status', 'FAILED'); }
    public function scopeSynced($query)   { return $query->where('status', 'SYNCED'); }
}
