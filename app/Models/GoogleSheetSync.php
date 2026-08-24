<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoogleSheetSync extends Model
{
    protected $fillable = [
        'name',
        'courier',
        'sheet_url',
        'sheet_gid',
        'is_active',
        'sync_interval_minutes',
        'last_sync_status',
        'last_sync_message',
        'last_sync_rows',
        'last_sync_inserted',
        'last_sync_updated',
        'last_sync_skipped',
        'last_sync_errors',
        'last_synced_at',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sync_interval_minutes' => 'integer',
        'last_sync_rows' => 'integer',
        'last_sync_inserted' => 'integer',
        'last_sync_updated' => 'integer',
        'last_sync_skipped' => 'integer',
        'last_sync_errors' => 'integer',
        'last_synced_at' => 'datetime',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Convert a Google Sheets sharing URL to a CSV export URL.
     * Supports both /d/e/ (published) and /d/ (standard) URL formats.
     *
     * @throws \InvalidArgumentException when the URL is not a recognized Google Sheets URL.
     *                                   Callers must validate the host before storing; this is a defense-in-depth check
     *                                   to ensure the server never fetches an arbitrary URL on sync (SSRF prevention).
     */
    public function getCsvExportUrl(): string
    {
        $url = trim((string) $this->sheet_url);
        $gid = $this->sheet_gid ?: '0';

        // Defense-in-depth: only allow Google Sheets hosts. The controller validates this
        // on input, but stale rows or direct DB edits must not be able to bypass it.
        $parsed = parse_url($url);
        if ($parsed === false
            || ($parsed['scheme'] ?? null) !== 'https'
            || ! in_array($parsed['host'] ?? '', ['docs.google.com', 'sheets.google.com'], true)
        ) {
            throw new \InvalidArgumentException('Only Google Sheets URLs are allowed for sync.');
        }

        // Published spreadsheet: https://docs.google.com/spreadsheets/d/e/{id}/pubhtml
        if (preg_match('#/spreadsheets/d/e/([a-zA-Z0-9_-]+)#', $url, $m)) {
            return "https://docs.google.com/spreadsheets/d/e/{$m[1]}/pub?gid={$gid}&single=true&output=csv";
        }

        // Standard spreadsheet: https://docs.google.com/spreadsheets/d/{id}/edit#gid=...
        if (preg_match('#/spreadsheets/d/([a-zA-Z0-9_-]+)#', $url, $m)) {
            return "https://docs.google.com/spreadsheets/d/{$m[1]}/export?format=csv&gid={$gid}&single=true";
        }

        // No recognized Google Sheets path — refuse rather than fetch an arbitrary URL.
        throw new \InvalidArgumentException('Unrecognized Google Sheets URL format.');
    }

    /**
     * Check if this sync config is due for its next scheduled run.
     */
    public function isDueForSync(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if (! $this->last_synced_at) {
            return true;
        }

        return $this->last_synced_at
            ->addMinutes($this->sync_interval_minutes)
            ->isPast();
    }
}
