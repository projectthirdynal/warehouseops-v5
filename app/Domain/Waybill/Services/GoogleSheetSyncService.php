<?php

declare(strict_types=1);

namespace App\Domain\Waybill\Services;

use App\Domain\Waybill\Models\GoogleConnection;
use App\Domain\Waybill\Models\GoogleSheetConfig;
use App\Imports\FlashWaybillFastImport;
use App\Imports\JntWaybillFastImport;
use App\Imports\SpxWaybillFastImport;
use App\Models\Upload;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleSheetSyncService
{
    private const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const SHEETS_API_BASE = 'https://sheets.googleapis.com/v4/spreadsheets';

    /**
     * Exchange authorization code for access + refresh tokens.
     */
    public function exchangeCode(string $code, string $redirectUri): array
    {
        $resp = Http::asForm()->post(self::GOOGLE_TOKEN_URL, [
            'code' => $code,
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);

        if (! $resp->successful()) {
            throw new \RuntimeException('Google token exchange failed: '.$resp->body());
        }

        return $resp->json();
    }

    /**
     * Refresh an expired access token using the stored refresh token.
     */
    public function refreshToken(GoogleConnection $connection): string
    {
        $resp = Http::asForm()->post(self::GOOGLE_TOKEN_URL, [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $connection->getDecryptedRefreshToken(),
            'grant_type' => 'refresh_token',
        ]);

        if (! $resp->successful()) {
            throw new \RuntimeException('Google token refresh failed: '.$resp->body());
        }

        $data = $resp->json();
        $expiresAt = now()->addSeconds($data['expires_in'] ?? 3600);

        $connection->update([
            'access_token' => $data['access_token'],
            'expires_at' => $expiresAt,
        ]);

        return $data['access_token'];
    }

    /**
     * Get a valid access token, refreshing if necessary.
     */
    public function getAccessToken(GoogleConnection $connection): string
    {
        if ($connection->isExpired() || $connection->expiresWithinMinutes(5)) {
            return $this->refreshToken($connection);
        }

        return $connection->getDecryptedAccessToken();
    }

    /**
     * Revoke the Google OAuth tokens.
     */
    public function revokeToken(GoogleConnection $connection): void
    {
        try {
            $refreshToken = $connection->getDecryptedRefreshToken();
            Http::asForm()->post(self::GOOGLE_TOKEN_URL.'/revoke', [
                'token' => $refreshToken,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Google token revoke failed: '.$e->getMessage());
        }
    }

    /**
     * Read all rows from a Google Sheet (with header row as keys).
     *
     * @return array<int, array<string, mixed>>
     */
    public function readSheet(GoogleConnection $connection, GoogleSheetConfig $config): array
    {
        $spreadsheetId = $config->getSpreadsheetId();
        if (! $spreadsheetId) {
            throw new \RuntimeException('Invalid Google Sheet URL — could not extract spreadsheet ID.');
        }

        $accessToken = $this->getAccessToken($connection);

        // Determine the range — use sheet tab name if specified, otherwise first sheet
        $range = $config->sheet_tab_name ? "'{$config->sheet_tab_name}'!A:Z" : 'A:Z';

        $url = self::SHEETS_API_BASE.'/'.$spreadsheetId.'/values/'.urlencode($range);

        $resp = Http::withToken($accessToken)
            ->get($url, ['majorDimension' => 'ROWS']);

        if (! $resp->successful()) {
            throw new \RuntimeException('Google Sheets API error: '.$resp->body());
        }

        $data = $resp->json();
        $values = $data['values'] ?? [];

        if (count($values) < 2) {
            return [];
        }

        // First row = headers
        $headers = array_map(fn ($h) => trim((string) $h), $values[0]);
        $rows = [];

        for ($i = 1; $i < count($values); $i++) {
            $row = $values[$i];
            if (empty(array_filter($row))) {
                continue;
            }

            $assoc = [];
            for ($j = 0; $j < count($headers); $j++) {
                $assoc[$headers[$j]] = $row[$j] ?? '';
            }
            $rows[] = $assoc;
        }

        return $rows;
    }

    /**
     * Sync a single sheet config — reads the sheet and runs the appropriate import.
     */
    public function syncConfig(GoogleSheetConfig $config, Upload $upload, int $userId): array
    {
        $connection = GoogleConnection::active();
        if (! $connection) {
            throw new \RuntimeException('No active Google connection. Please connect your Google account first.');
        }

        $rows = $this->readSheet($connection, $config);

        if (empty($rows)) {
            return ['rows_read' => 0, 'imported' => 0, 'errors' => 0];
        }

        $courier = strtolower($config->courier);
        $import = match ($courier) {
            'jnt' => new JntWaybillFastImport($upload, $userId),
            'flash' => new FlashWaybillFastImport($upload, $userId),
            'spx' => new SpxWaybillFastImport($upload, $userId),
            default => throw new \RuntimeException("Unsupported courier: {$courier}"),
        };

        // All importers expect a file path — write rows to a temp CSV
        $tempFile = $this->writeRowsToTempCsv($rows);
        try {
            $import->import($tempFile);
        } finally {
            @unlink($tempFile);
        }

        return [
            'rows_read' => count($rows),
            'imported' => $import->getSuccessCount(),
            'errors' => $import->getErrorCount(),
            'inserted' => $import->getInsertedCount(),
            'updated' => $import->getUpdatedCount(),
            'skipped' => $import->getSkippedCount(),
            'error_details' => $import->getErrors(),
        ];
    }

    /**
     * Write rows to a temporary CSV file for file-based importers (J&T, Flash).
     */
    protected function writeRowsToTempCsv(array $rows): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'gsheet_sync_').'.csv';
        $fp = fopen($tempFile, 'w');

        // Write headers from first row
        if (! empty($rows)) {
            fputcsv($fp, array_keys($rows[0]));
            foreach ($rows as $row) {
                fputcsv($fp, array_values($row));
            }
        }

        fclose($fp);

        return $tempFile;
    }

    /**
     * Build the Google OAuth authorization URL.
     */
    public function getAuthUrl(string $redirectUri, string $state): string
    {
        $params = http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/spreadsheets.readonly https://www.googleapis.com/auth/userinfo.email',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.$params;
    }

    /**
     * Get the Google user's email using the access token.
     */
    public function getUserInfo(string $accessToken): array
    {
        $resp = Http::withToken($accessToken)
            ->get('https://www.googleapis.com/oauth2/v2/userinfo');

        if (! $resp->successful()) {
            return [];
        }

        return $resp->json();
    }
}
