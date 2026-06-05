<?php

namespace App\Http\Controllers;

use App\Models\Upload;
use App\Jobs\ProcessStreamingChunk;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Validator;

class WaybillStreamingImportController extends Controller
{
    /**
     * Initialize a streaming upload session
     */
    public function initiate(Request $request)
    {
        $validated = $request->validate([
            'courier' => 'required|in:jnt,flash',
            'filename' => 'required|string',
            'total_rows' => 'required|integer|min:1',
            'file_size' => 'required|integer|max:104857600', // 100MB
        ]);

        $upload = Upload::create([
            'uuid' => Str::uuid(),
            'filename' => 'streaming_' . time() . '.xlsx',
            'original_filename' => $validated['filename'],
            'courier' => $validated['courier'],
            'type' => 'waybill',
            'import_type' => 'streaming',
            'status' => Upload::STATUS_PENDING,
            'total_rows' => $validated['total_rows'],
            'uploaded_by' => auth()->id(),
            'metadata' => [
                'file_size' => $validated['file_size'],
                'streaming' => true,
            ],
        ]);

        return response()->json([
            'upload_id' => $upload->id,
            'uuid' => $upload->uuid,
            'chunk_size' => 1000, // Rows per chunk
        ]);
    }

    /**
     * Receive and process a data chunk
     */
    public function uploadChunk(Request $request, $uploadId)
    {
        $upload = Upload::findOrFail($uploadId);

        if ($upload->status === Upload::STATUS_CANCELLED) {
            return response()->json(['error' => 'Upload cancelled'], 400);
        }

        $validated = $request->validate([
            'chunk_number' => 'required|integer|min:0',
            'data' => 'required|array',
            'data.*' => 'required|array',
            'is_last_chunk' => 'boolean',
        ]);

        try {
            // Validate data structure
            $this->validateChunkData($validated['data'], $upload->courier);

            // Store chunk in Redis temporarily
            $key = "upload:{$upload->id}:chunk:{$validated['chunk_number']}";
            Redis::setex($key, 3600, json_encode($validated['data']));

            // Update upload status
            if ($upload->status === Upload::STATUS_PENDING) {
                $upload->update(['status' => Upload::STATUS_PROCESSING]);
            }

            // Dispatch processing job immediately
            ProcessStreamingChunk::dispatch(
                $upload->id,
                $validated['chunk_number'],
                count($validated['data'])
            );

            // If last chunk, mark as ready for completion check
            if ($validated['is_last_chunk'] ?? false) {
                Redis::set("upload:{$upload->id}:last_chunk", $validated['chunk_number']);
            }

            return response()->json([
                'success' => true,
                'chunk_number' => $validated['chunk_number'],
                'rows_received' => count($validated['data']),
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'chunk_number' => $validated['chunk_number'],
            ], 422);
        }
    }

    /**
     * Get upload progress
     */
    public function progress($uploadId)
    {
        $upload = Upload::with('chunks')->findOrFail($uploadId);

        return response()->json([
            'upload_id' => $upload->id,
            'status' => $upload->status,
            'total_rows' => $upload->total_rows,
            'processed_rows' => $upload->processed_rows,
            'inserted_rows' => $upload->inserted_rows,
            'updated_rows' => $upload->updated_rows,
            'error_rows' => $upload->error_rows,
            'chunks' => [
                'total' => $upload->total_chunks,
                'processed' => $upload->processed_chunks,
            ],
            'progress_percentage' => $upload->total_rows > 0 
                ? round(($upload->processed_rows / $upload->total_rows) * 100, 2)
                : 0,
        ]);
    }

    /**
     * Cancel upload
     */
    public function cancel($uploadId)
    {
        $upload = Upload::findOrFail($uploadId);
        
        $upload->update([
            'status' => Upload::STATUS_CANCELLED,
            'completed_at' => now(),
        ]);

        // Clean up Redis chunks
        $keys = Redis::keys("upload:{$upload->id}:chunk:*");
        if (!empty($keys)) {
            Redis::del($keys);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Validate chunk data structure
     */
    protected function validateChunkData(array $data, string $courier): void
    {
        if (empty($data)) {
            throw new \InvalidArgumentException('Chunk data cannot be empty');
        }

        $requiredFields = $courier === 'jnt'
            ? ['waybill_number', 'order_status']
            : ['tracking_no', 'status'];

        foreach ($data as $index => $row) {
            foreach ($requiredFields as $field) {
                if (!isset($row[$field]) || empty($row[$field])) {
                    throw new \InvalidArgumentException(
                        "Row {$index}: Missing required field '{$field}'"
                    );
                }
            }
        }
    }
}
