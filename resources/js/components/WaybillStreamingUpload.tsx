import React, { useState } from 'react';
import { read, utils } from 'xlsx';
import { router } from '@inertiajs/react';

interface StreamingUploadProps {
  courier: 'jnt' | 'flash';
  onComplete?: (uploadId: number) => void;
}

export default function WaybillStreamingUpload({ courier, onComplete }: StreamingUploadProps) {
  const [file, setFile] = useState<File | null>(null);
  const [uploading, setUploading] = useState(false);
  const [progress, setProgress] = useState(0);
  const [status, setStatus] = useState('');
  const [uploadId, setUploadId] = useState<number | null>(null);

  const handleFileSelect = (e: React.ChangeEvent<HTMLInputElement>) => {
    const selectedFile = e.target.files?.[0];
    if (selectedFile) {
      setFile(selectedFile);
      setProgress(0);
      setStatus('');
    }
  };

  const processFileInChunks = async (file: File) => {
    setUploading(true);
    setStatus('Reading file...');

    try {
      // Read file
      const arrayBuffer = await file.arrayBuffer();
      const workbook = read(arrayBuffer);
      const worksheet = workbook.Sheets[workbook.SheetNames[0]];
      const jsonData = utils.sheet_to_json(worksheet);

      if (jsonData.length === 0) {
        throw new Error('File is empty');
      }

      setStatus('Initializing upload...');

      // Initialize upload session
      const initResponse = await fetch('/api/waybills/streaming/initiate', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN':
            document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
        body: JSON.stringify({
          courier,
          filename: file.name,
          total_rows: jsonData.length,
          file_size: file.size,
        }),
      });

      if (!initResponse.ok) {
        throw new Error('Failed to initialize upload');
      }

      const { upload_id, chunk_size } = await initResponse.json();
      setUploadId(upload_id);

      // Split data into chunks
      const chunks = [];
      for (let i = 0; i < jsonData.length; i += chunk_size) {
        chunks.push(jsonData.slice(i, i + chunk_size));
      }

      setStatus(`Uploading ${chunks.length} chunks...`);

      // Upload chunks sequentially (or in parallel with limit)
      for (let i = 0; i < chunks.length; i++) {
        const isLastChunk = i === chunks.length - 1;

        await fetch(`/api/waybills/streaming/${upload_id}/chunk`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN':
              document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
          },
          body: JSON.stringify({
            chunk_number: i,
            data: chunks[i],
            is_last_chunk: isLastChunk,
          }),
        });

        // Update progress
        const progressPercent = Math.round(((i + 1) / chunks.length) * 100);
        setProgress(progressPercent);
        setStatus(`Uploaded ${i + 1}/${chunks.length} chunks (${progressPercent}%)`);
      }

      setStatus('Upload complete! Processing data...');

      // Poll for completion
      await pollProgress(upload_id);
    } catch (error) {
      console.error('Upload error:', error);
      setStatus(`Error: ${error instanceof Error ? error.message : 'Unknown error'}`);
      setUploading(false);
    }
  };

  const pollProgress = async (uploadId: number) => {
    const pollInterval = setInterval(async () => {
      try {
        const response = await fetch(`/api/waybills/streaming/${uploadId}/progress`);
        const data = await response.json();

        setStatus(
          `Processing: ${data.processed_rows}/${data.total_rows} rows (${data.progress_percentage}%)`
        );

        if (data.status === 'completed' || data.status === 'completed_with_errors') {
          clearInterval(pollInterval);
          setUploading(false);
          setStatus(`Complete! Inserted: ${data.inserted_rows}, Updated: ${data.updated_rows}`);

          if (onComplete) {
            onComplete(uploadId);
          } else {
            // Redirect to waybills page
            setTimeout(() => {
              router.visit('/waybills');
            }, 2000);
          }
        } else if (data.status === 'failed') {
          clearInterval(pollInterval);
          setUploading(false);
          setStatus('Import failed. Please check the error log.');
        }
      } catch (error) {
        console.error('Poll error:', error);
      }
    }, 2000);

    // Timeout after 30 minutes
    setTimeout(() => clearInterval(pollInterval), 1800000);
  };

  const handleCancel = async () => {
    if (uploadId && window.confirm('Cancel this upload?')) {
      await fetch(`/api/waybills/streaming/${uploadId}/cancel`, {
        method: 'POST',
        headers: {
          'X-CSRF-TOKEN':
            document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
      });
      setUploading(false);
      setStatus('Upload cancelled');
      setUploadId(null);
    }
  };

  return (
    <div className="space-y-4">
      <div className="border-2 border-dashed border-border rounded-lg p-6">
        <input
          type="file"
          accept=".xlsx,.xls,.csv"
          onChange={handleFileSelect}
          disabled={uploading}
          className="block w-full text-sm text-muted-foreground
            file:mr-4 file:py-2 file:px-4
            file:rounded-md file:border-0
            file:text-sm file:font-semibold
            file:bg-info/5 file:text-info
            hover:file:bg-info/10
            disabled:opacity-50"
        />

        {file && (
          <div className="mt-4 text-sm text-muted-foreground">
            <p>
              <strong>File:</strong> {file.name}
            </p>
            <p>
              <strong>Size:</strong> {(file.size / 1024 / 1024).toFixed(2)} MB
            </p>
            <p>
              <strong>Courier:</strong> {courier.toUpperCase()}
            </p>
          </div>
        )}
      </div>

      {file && !uploading && (
        <button
          onClick={() => processFileInChunks(file)}
          className="w-full bg-info text-white py-2 px-4 rounded-md hover:bg-info/80 transition"
        >
          Start Streaming Upload
        </button>
      )}

      {uploading && (
        <div className="space-y-3">
          <div className="w-full bg-muted/80 rounded-full h-4">
            <div
              className="bg-info h-4 rounded-full transition-all duration-300"
              style={{ width: `${progress}%` }}
            />
          </div>

          <p className="text-sm text-muted-foreground text-center">{status}</p>

          <button
            onClick={handleCancel}
            className="w-full bg-destructive text-white py-2 px-4 rounded-md hover:bg-destructive/80 transition"
          >
            Cancel Upload
          </button>
        </div>
      )}

      <div className="bg-info/5 border border-info/20 rounded-lg p-4 text-sm">
        <h4 className="font-semibold text-info mb-2">✨ Streaming Upload Benefits:</h4>
        <ul className="list-disc list-inside text-info space-y-1">
          <li>No file size limits - processes data in chunks</li>
          <li>Faster - starts processing immediately</li>
          <li>Real-time progress tracking</li>
          <li>Lower memory usage</li>
          <li>Can cancel anytime</li>
        </ul>
      </div>
    </div>
  );
}
