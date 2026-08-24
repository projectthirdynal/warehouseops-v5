<?php

declare(strict_types=1);

use App\Models\GoogleSheetSync;

beforeEach(function () {
    // Build the model in memory only — no DB persistence required for these tests.
    $this->makeSync = function (array $attrs = []): GoogleSheetSync {
        return GoogleSheetSync::make(array_merge([
            'name' => 'Test sync',
            'courier' => 'jnt',
            'sheet_url' => 'https://docs.google.com/spreadsheets/d/ABC123/edit',
            'sheet_gid' => '0',
            'is_active' => true,
            'sync_interval_minutes' => 15,
        ], $attrs));
    };
});

describe('getCsvExportUrl', function () {
    it('converts a standard spreadsheet edit URL to a CSV export URL', function () {
        $sync = ($this->makeSync)();

        expect($sync->getCsvExportUrl())
            ->toBe('https://docs.google.com/spreadsheets/d/ABC123/export?format=csv&gid=0&single=true');
    });

    it('honors a non-default sheet gid', function () {
        $sync = ($this->makeSync)(['sheet_gid' => '12345']);

        expect($sync->getCsvExportUrl())
            ->toBe('https://docs.google.com/spreadsheets/d/ABC123/export?format=csv&gid=12345&single=true');
    });

    it('defaults the gid to 0 when none is set', function () {
        $sync = ($this->makeSync)(['sheet_gid' => null]);

        expect($sync->getCsvExportUrl())
            ->toContain('gid=0');
    });

    it('converts a published spreadsheet URL (/d/e/...)', function () {
        $sync = ($this->makeSync)([
            'sheet_url' => 'https://docs.google.com/spreadsheets/d/e/2PACX-abc/pubhtml',
        ]);

        expect($sync->getCsvExportUrl())
            ->toBe('https://docs.google.com/spreadsheets/d/e/2PACX-abc/pub?gid=0&single=true&output=csv');
    });

    it('rejects non-https URLs (SSRF guard)', function () {
        $sync = ($this->makeSync)([
            'sheet_url' => 'http://docs.google.com/spreadsheets/d/ABC123/edit',
        ]);

        expect(fn () => $sync->getCsvExportUrl())
            ->toThrow(InvalidArgumentException::class);
    });

    it('rejects non-Google hosts (SSRF guard)', function () {
        $sync = ($this->makeSync)([
            'sheet_url' => 'https://evil.example.com/spreadsheets/d/ABC123/export?format=csv',
        ]);

        expect(fn () => $sync->getCsvExportUrl())
            ->toThrow(InvalidArgumentException::class);
    });

    it('rejects internal / metadata endpoints even when https (SSRF guard)', function () {
        $sync = ($this->makeSync)([
            'sheet_url' => 'https://169.254.169.254/latest/meta-data/iam/security-credentials/role',
        ]);

        expect(fn () => $sync->getCsvExportUrl())
            ->toThrow(InvalidArgumentException::class);
    });

    it('rejects a Google host URL that does not contain a spreadsheet path', function () {
        $sync = ($this->makeSync)([
            'sheet_url' => 'https://docs.google.com/forms/d/XYZ123/edit',
        ]);

        expect(fn () => $sync->getCsvExportUrl())
            ->toThrow(InvalidArgumentException::class);
    });
});

describe('isDueForSync', function () {
    it('is due when never synced before', function () {
        $sync = ($this->makeSync)(['last_synced_at' => null]);

        expect($sync->isDueForSync())->toBeTrue();
    });

    it('is not due when the interval has not elapsed', function () {
        $sync = ($this->makeSync)([
            'last_synced_at' => now()->subMinutes(5),
            'sync_interval_minutes' => 15,
        ]);

        expect($sync->isDueForSync())->toBeFalse();
    });

    it('is due when the interval has elapsed', function () {
        $sync = ($this->makeSync)([
            'last_synced_at' => now()->subMinutes(20),
            'sync_interval_minutes' => 15,
        ]);

        expect($sync->isDueForSync())->toBeTrue();
    });

    it('is never due when inactive', function () {
        $sync = ($this->makeSync)([
            'is_active' => false,
            'last_synced_at' => null,
        ]);

        expect($sync->isDueForSync())->toBeFalse();
    });

    it('is never due when inactive even if interval elapsed', function () {
        $sync = ($this->makeSync)([
            'is_active' => false,
            'last_synced_at' => now()->subHours(2),
            'sync_interval_minutes' => 15,
        ]);

        expect($sync->isDueForSync())->toBeFalse();
    });
});
