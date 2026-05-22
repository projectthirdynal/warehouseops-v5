<?php

namespace App\Services;

use App\Models\SmsLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsService
{
    private string $apiUrl;
    private string $apiKey;

    public function __construct()
    {
        $this->apiUrl = config('services.skysms.url', 'https://skysms.skyio.site/api/v1/sms');
        $this->apiKey = config('services.skysms.key', 'sk_deoYwH46rnXLBJUFbzoSbSyR0lOYzuQK');
    }

    /**
     * Send a single SMS message
     */
    public function send(string $phone, string $message, array $context = []): array
    {
        $phone = $this->normalizePhone($phone);

        $log = SmsLog::create([
            'campaign_id' => $context['campaign_id'] ?? null,
            'sequence_id' => $context['sequence_id'] ?? null,
            'waybill_id' => $context['waybill_id'] ?? null,
            'lead_id' => $context['lead_id'] ?? null,
            'phone' => $phone,
            'message' => $message,
            'status' => 'pending',
        ]);

        try {
            $response = Http::withHeaders([
                'X-API-Key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post("{$this->apiUrl}/send", [
                'phone' => $phone,
                'message' => $message,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $log->update([
                    'status' => 'sent',
                    'external_id' => $data['id'] ?? null,
                    'cost' => $data['cost'] ?? null,
                    'sent_at' => now(),
                ]);

                return [
                    'success' => true,
                    'log_id' => $log->id,
                    'external_id' => $data['id'] ?? null,
                ];
            }

            $error = $response->json('message') ?? $response->body();
            $log->update([
                'status' => 'failed',
                'error_message' => $error,
            ]);

            return [
                'success' => false,
                'error' => $error,
                'log_id' => $log->id,
            ];

        } catch (\Exception $e) {
            Log::error('SMS send failed', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);

            $log->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'log_id' => $log->id,
            ];
        }
    }

    /**
     * Send bulk SMS messages
     */
    public function sendBulk(array $recipients, string $message, ?int $campaignId = null): array
    {
        $formattedRecipients = [];
        $logs = [];

        foreach ($recipients as $recipient) {
            $phone = $this->normalizePhone($recipient['phone'] ?? $recipient);
            $personalizedMessage = $this->personalizeMessage($message, $recipient);

            $log = SmsLog::create([
                'campaign_id' => $campaignId,
                'waybill_id' => $recipient['waybill_id'] ?? null,
                'lead_id' => $recipient['lead_id'] ?? null,
                'phone' => $phone,
                'message' => $personalizedMessage,
                'status' => 'pending',
            ]);

            $logs[] = $log;
            $formattedRecipients[] = [
                'phone' => $phone,
                'message' => $personalizedMessage,
            ];
        }

        try {
            // Send in batches of 100 to avoid API limits
            $batches = array_chunk($formattedRecipients, 100);
            $logBatches = array_chunk($logs, 100);
            $totalSent = 0;
            $totalFailed = 0;

            foreach ($batches as $batchIndex => $batch) {
                $response = Http::withHeaders([
                    'X-API-Key' => $this->apiKey,
                    'Content-Type' => 'application/json',
                ])->timeout(120)->post("{$this->apiUrl}/send-bulk", [
                    'recipients' => $batch,
                ]);

                $currentLogs = $logBatches[$batchIndex];

                if ($response->successful()) {
                    $data = $response->json();
                    $results = $data['results'] ?? [];

                    foreach ($currentLogs as $index => $log) {
                        $result = $results[$index] ?? null;
                        if ($result && ($result['success'] ?? false)) {
                            $log->update([
                                'status' => 'sent',
                                'external_id' => $result['id'] ?? null,
                                'cost' => $result['cost'] ?? null,
                                'sent_at' => now(),
                            ]);
                            $totalSent++;
                        } else {
                            $log->update([
                                'status' => 'failed',
                                'error_message' => $result['error'] ?? 'Unknown error',
                            ]);
                            $totalFailed++;
                        }
                    }
                } else {
                    $error = $response->json('message') ?? $response->body();
                    foreach ($currentLogs as $log) {
                        $log->update([
                            'status' => 'failed',
                            'error_message' => $error,
                        ]);
                        $totalFailed++;
                    }
                }

                // Small delay between batches to avoid rate limiting
                if ($batchIndex < count($batches) - 1) {
                    usleep(100000); // 100ms
                }
            }

            return [
                'success' => true,
                'total' => count($recipients),
                'sent' => $totalSent,
                'failed' => $totalFailed,
            ];

        } catch (\Exception $e) {
            Log::error('Bulk SMS failed', [
                'campaign_id' => $campaignId,
                'error' => $e->getMessage(),
            ]);

            foreach ($logs as $log) {
                if ($log->status === 'pending') {
                    $log->update([
                        'status' => 'failed',
                        'error_message' => $e->getMessage(),
                    ]);
                }
            }

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'total' => count($recipients),
                'sent' => 0,
                'failed' => count($recipients),
            ];
        }
    }

    /**
     * Normalize phone number to standard format
     */
    public function normalizePhone(string $phone): string
    {
        // Remove all non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Handle Philippine numbers
        if (strlen($phone) === 10 && str_starts_with($phone, '9')) {
            $phone = '63' . $phone;
        } elseif (strlen($phone) === 11 && str_starts_with($phone, '0')) {
            $phone = '63' . substr($phone, 1);
        }

        return $phone;
    }

    /**
     * Personalize message with recipient data
     */
    public function personalizeMessage(string $message, $recipient): string
    {
        if (is_string($recipient)) {
            return $message;
        }

        $replacements = [
            '{name}' => $recipient['name'] ?? '',
            '{waybill}' => $recipient['waybill_number'] ?? '',
            '{status}' => $recipient['status'] ?? '',
            '{amount}' => isset($recipient['amount']) ? number_format($recipient['amount'], 2) : '',
            '{cod}' => isset($recipient['cod_amount']) ? number_format($recipient['cod_amount'], 2) : '',
            '{tracking}' => $recipient['waybill_number'] ?? $recipient['tracking'] ?? '',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $message);
    }

    /**
     * Get available message variables
     */
    public function getAvailableVariables(): array
    {
        return [
            '{name}' => 'Recipient name',
            '{waybill}' => 'Waybill/tracking number',
            '{status}' => 'Current status',
            '{amount}' => 'Order amount',
            '{cod}' => 'COD amount',
            '{tracking}' => 'Tracking number',
        ];
    }

    /**
     * Check API connectivity
     */
    public function checkConnection(): array
    {
        try {
            $response = Http::withHeaders([
                'X-API-Key' => $this->apiKey,
            ])->get("{$this->apiUrl}/balance");

            if ($response->successful()) {
                return [
                    'connected' => true,
                    'balance' => $response->json('balance') ?? null,
                ];
            }

            return [
                'connected' => false,
                'error' => $response->json('message') ?? 'Connection failed',
            ];

        } catch (\Exception $e) {
            return [
                'connected' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
