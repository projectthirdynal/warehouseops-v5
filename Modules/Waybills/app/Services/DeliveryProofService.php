<?php

declare(strict_types=1);

namespace Modules\Waybills\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Waybills\Models\DeliveryProof;
use Modules\Waybills\Models\Waybill;

class DeliveryProofService
{
    private const DISK = 'public';

    private const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
    ];

    private const MAX_FILE_KB = 10240; // 10 MB

    /**
     * Store a manually uploaded delivery proof file.
     */
    public function storeUpload(Waybill $waybill, UploadedFile $file, string $type = 'photo', ?int $userId = null): DeliveryProof
    {
        $this->validateFile($file);

        $directory = "delivery-proofs/{$waybill->id}";
        $storedPath = $file->store($directory, self::DISK);

        return DeliveryProof::create([
            'waybill_id' => $waybill->id,
            'type' => $type,
            'file_path' => $storedPath,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'source' => 'manual_upload',
            'uploaded_by' => $userId,
        ]);
    }

    /**
     * Store a proof file fetched from a courier callback URL.
     *
     * @param  array{url?: string, type?: string, filename?: string, metadata?: array}  $proofData
     */
    public function storeFromCourierCallback(Waybill $waybill, string $courierCode, array $proofData): ?DeliveryProof
    {
        $url = $proofData['url'] ?? null;
        if (! $url || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $type = $proofData['type'] ?? 'photo';
        $filename = $proofData['filename'] ?? basename(parse_url($url, PHP_URL_PATH)) ?? 'proof.jpg';

        try {
            $response = Http::timeout(30)->get($url);

            if (! $response->successful()) {
                Log::warning('Failed to download delivery proof from courier callback', [
                    'waybill' => $waybill->waybill_number,
                    'url' => $url,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $mimeType = $response->header('Content-Type', 'image/jpeg');
            $contents = $response->body();

            if (strlen($contents) > self::MAX_FILE_KB * 1024) {
                Log::warning('Delivery proof file too large from courier callback', [
                    'waybill' => $waybill->waybill_number,
                    'size' => strlen($contents),
                ]);

                return null;
            }

            $extension = $this->guessExtension($mimeType, $filename);
            $directory = "delivery-proofs/{$waybill->id}";
            $storedPath = "{$directory}/".uniqid('courier_', true).".{$extension}";

            Storage::disk(self::DISK)->put($storedPath, $contents);

            return DeliveryProof::create([
                'waybill_id' => $waybill->id,
                'type' => $type,
                'file_path' => $storedPath,
                'original_filename' => $filename,
                'mime_type' => $mimeType,
                'file_size' => strlen($contents),
                'source' => 'courier_callback',
                'courier_code' => $courierCode,
                'metadata' => array_merge(
                    ['source_url' => $url],
                    $proofData['metadata'] ?? [],
                ),
            ]);
        } catch (\Throwable $e) {
            Log::error('Exception downloading delivery proof from courier callback', [
                'waybill' => $waybill->waybill_number,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Extract proof data from a courier webhook payload.
     * Checks for common fields like photoUrl, signatureUrl, podUrl in the raw data.
     *
     * @return array<int, array{url: string, type: string, filename?: string}>
     */
    public function extractProofsFromWebhook(array $rawData): array
    {
        $proofs = [];

        // Flash Express: data may contain picUrl, signPicUrl
        $flashFields = [
            'picUrl' => 'photo',
            'signPicUrl' => 'signature',
            'podUrl' => 'pod_document',
        ];

        // J&T Express: may contain scanPhotoUrl, signatureUrl
        $jntFields = [
            'scanPhotoUrl' => 'photo',
            'signatureUrl' => 'signature',
            'podUrl' => 'pod_document',
            'picUrl' => 'photo',
        ];

        $allFields = array_merge($flashFields, $jntFields);

        // Check top-level
        foreach ($allFields as $field => $type) {
            if (! empty($rawData[$field]) && filter_var($rawData[$field], FILTER_VALIDATE_URL)) {
                $proofs[] = [
                    'url' => $rawData[$field],
                    'type' => $type,
                    'filename' => $field.'_'.basename(parse_url($rawData[$field], PHP_URL_PATH)),
                ];
            }
        }

        // Check nested data object (Flash style)
        $inner = $rawData['data'] ?? [];
        if (is_array($inner)) {
            foreach ($allFields as $field => $type) {
                if (! empty($inner[$field]) && filter_var($inner[$field], FILTER_VALIDATE_URL)) {
                    $proofs[] = [
                        'url' => $inner[$field],
                        'type' => $type,
                        'filename' => $field.'_'.basename(parse_url($inner[$field], PHP_URL_PATH)),
                    ];
                }
            }
        }

        return $proofs;
    }

    /**
     * Delete a delivery proof and its file.
     */
    public function delete(DeliveryProof $proof): bool
    {
        Storage::disk(self::DISK)->delete($proof->file_path);

        return $proof->delete();
    }

    /**
     * Get proofs for a waybill grouped by type.
     */
    public function getForWaybill(Waybill $waybill): array
    {
        $proofs = $waybill->deliveryProofs()->get();

        return [
            'photos' => $proofs->where('type', 'photo')->values(),
            'signatures' => $proofs->where('type', 'signature')->values(),
            'documents' => $proofs->where('type', 'pod_document')->values(),
            'other' => $proofs->where('type', 'other')->values(),
            'all' => $proofs,
        ];
    }

    protected function validateFile(UploadedFile $file): void
    {
        $mime = $file->getMimeType();
        if (! in_array($mime, self::ALLOWED_MIMES)) {
            throw new \InvalidArgumentException("Unsupported file type: {$mime}. Allowed: jpeg, png, gif, webp, pdf.");
        }

        $sizeKb = $file->getSize() / 1024;
        if ($sizeKb > self::MAX_FILE_KB) {
            throw new \InvalidArgumentException('File too large: '.round($sizeKb).' KB. Max: '.self::MAX_FILE_KB.' KB.');
        }
    }

    protected function guessExtension(string $mimeType, string $filename): string
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
        ];

        return $map[$mimeType] ?? pathinfo($filename, PATHINFO_EXTENSION) ?: 'jpg';
    }
}
