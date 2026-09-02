<?php

declare(strict_types=1);

namespace App\Domain\Waybill\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoogleSheetConfig extends Model
{
    protected $table = 'google_sheet_configs';

    protected $fillable = [
        'courier',
        'month',
        'data_year',
        'sheet_url',
        'enabled',
        'updated_by',
    ];

    protected $casts = [
        'data_year' => 'integer',
        'enabled' => 'boolean',
    ];

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Extract the spreadsheet ID from a Google Sheets URL.
     * e.g. https://docs.google.com/spreadsheets/d/1abc123/edit → 1abc123
     */
    public function getSpreadsheetId(): ?string
    {
        if (! $this->sheet_url) {
            return null;
        }

        if (preg_match('#/spreadsheets/d/([a-zA-Z0-9-_]+)#', $this->sheet_url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Extract the gid from a Google Sheets URL (if present).
     */
    public function getSheetGid(): ?string
    {
        if (! $this->sheet_url) {
            return null;
        }

        if (preg_match('/[#&?]gid=(\d+)/', $this->sheet_url, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
