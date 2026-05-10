import { Head, router } from '@inertiajs/react';
import { useState, useRef, useEffect, useCallback } from 'react';
import {
  Upload,
  FileSpreadsheet,
  CheckCircle,
  XCircle,
  Clock,
  RefreshCw,
  Download,
  AlertCircle,
  Loader2,
  Eye,
  StopCircle,
  Ban,
  X,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Progress } from '@/components/ui/progress';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { formatDateTime } from '@/lib/utils';

interface UploadRecord {
  id: number;
  filename: string;
  original_filename: string;
  courier: string | null;
  import_type: string | null;
  total_rows: number;
  processed_rows: number;
  success_rows: number;
  inserted_rows: number;
  updated_rows: number;
  skipped_rows: number;
  error_rows: number;
  status: 'queued' | 'validating' | 'validation_failed' | 'ready_to_process' | 'pending' | 'processing' | 'completed' | 'completed_with_errors' | 'failed' | 'cancelled';
  errors: Array<{ row: number; error: string }> | null;
  uploaded_by: { name: string } | null;
  created_at: string;
}

interface UploadProgress {
  id: number;
  status: string;
  courier: string | null;
  import_type: string | null;
  total_rows: number;
  processed_rows: number;
  success_rows: number;
  inserted_rows: number;
  updated_rows: number;
  skipped_rows: number;
  error_rows: number;
}

interface ValidationResult {
  valid: boolean;
  total_rows_detected: string | number;
  detected_columns: string[];
  sample_rows: Record<string, unknown>[];
  duplicate_waybills_count: number;
  missing_headers: string[];
  warnings: string[];
  errors?: string[];
}

interface Props {
  uploads: {
    data: UploadRecord[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
  };
  stats: {
    total_uploads: number;
    total_imported: number;
    pending_uploads: number;
    recent_errors: number;
  };
}

type UploadPhase = 'idle' | 'uploading' | 'validating' | 'preview' | 'starting' | 'done';

const formatImportType = (type: string | null) => {
  if (!type) return '';
  return type.split('_').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');
};

export default function WaybillImport({ uploads, stats }: Props) {
  const fileInputRef = useRef<HTMLInputElement>(null);
  const xhrRef = useRef<XMLHttpRequest | null>(null);
  const pollTimerRef = useRef<ReturnType<typeof setInterval> | null>(null);

  const [selectedFile, setSelectedFile] = useState<File | null>(null);
  const [selectedCourier, setSelectedCourier] = useState<string>('jnt');
  const [selectedImportType, setSelectedImportType] = useState<string>('new_waybill');
  const [dragOver, setDragOver] = useState(false);
  const [uploadError, setUploadError] = useState<string | null>(null);
  const [transferPct, setTransferPct] = useState<number | null>(null);
  const [liveProgress, setLiveProgress] = useState<Record<number, UploadProgress>>({});
  const [uploadPhase, setUploadPhase] = useState<UploadPhase>('idle');
  const [validationResult, setValidationResult] = useState<ValidationResult | null>(null);
  const [pendingUploadId, setPendingUploadId] = useState<number | null>(null);

  const processingIds = uploads.data
    .filter((u) => ['pending', 'processing', 'queued', 'validating'].includes(u.status))
    .map((u) => u.id);

  const pollStatus = useCallback(async (id: number) => {
    try {
      const res = await fetch(`/waybills/import/${id}/status`, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      });
      if (!res.ok) return;
      const data: UploadProgress = await res.json();
      setLiveProgress((prev) => ({ ...prev, [id]: data }));

      if (['completed', 'completed_with_errors', 'failed', 'cancelled', 'validation_failed'].includes(data.status)) {
        router.reload({ only: ['uploads', 'stats'] });
      }
    } catch {
      // ignore transient network errors
    }
  }, []);

  useEffect(() => {
    if (processingIds.length === 0) {
      if (pollTimerRef.current) clearInterval(pollTimerRef.current);
      return;
    }

    processingIds.forEach(pollStatus);
    pollTimerRef.current = setInterval(() => {
      processingIds.forEach(pollStatus);
    }, 3000);

    return () => {
      if (pollTimerRef.current) clearInterval(pollTimerRef.current);
    };
  }, [processingIds.join(','), pollStatus]);

  const handleFileSelect = (file: File) => {
    setSelectedFile(file);
    setUploadError(null);
  };

  const handleDrop = (e: React.DragEvent) => {
    e.preventDefault();
    setDragOver(false);
    const file = e.dataTransfer.files[0];
    if (file && isValidFile(file)) handleFileSelect(file);
  };

  const isValidFile = (file: File): boolean => {
    const validTypes = [
      'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      'application/vnd.ms-excel',
      'text/csv',
    ];
    return validTypes.includes(file.type) || file.name.match(/\.(xlsx|xls|csv)$/i) !== null;
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedFile) return;

    setUploadPhase('uploading');
    setUploadError(null);
    setValidationResult(null);
    setTransferPct(0);

    const formData = new FormData();
    formData.append('file', selectedFile);
    formData.append('courier', selectedCourier);
    formData.append('import_type', selectedImportType);

    let uploadId: number;
    try {
      uploadId = await new Promise<number>((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhrRef.current = xhr;

        xhr.upload.onprogress = (event) => {
          if (event.lengthComputable) setTransferPct(Math.round((event.loaded / event.total) * 100));
        };

        xhr.onload = () => {
          xhrRef.current = null;
          setTransferPct(null);
          if (xhr.status >= 200 && xhr.status < 300) {
            try {
              const body = JSON.parse(xhr.responseText);
              resolve(body.upload_id);
            } catch {
              reject(new Error('Invalid server response.'));
            }
          } else {
            try {
              const body = JSON.parse(xhr.responseText);
              reject(new Error(body?.errors?.file?.[0] ?? body?.message ?? 'Upload failed.'));
            } catch {
              reject(new Error('Upload failed.'));
            }
          }
        };

        xhr.onerror = () => { xhrRef.current = null; setTransferPct(null); reject(new Error('Network error.')); };

        const csrfToken = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '';
        xhr.open('POST', '/waybills/import');
        xhr.setRequestHeader('X-CSRF-TOKEN', csrfToken);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.send(formData);
      });
    } catch (err) {
      setUploadError(err instanceof Error ? err.message : 'Upload failed.');
      setUploadPhase('idle');
      return;
    }

    setUploadPhase('validating');
    setPendingUploadId(uploadId);
    try {
      const csrfToken = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '';
      const res = await fetch(`/waybills/import/${uploadId}/validate`, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrfToken, 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
      });
      const result: ValidationResult = await res.json();
      setValidationResult(result);
      setUploadPhase('preview');
      router.reload({ only: ['uploads', 'stats'] });
    } catch {
      setUploadError('Validation request failed. Please try again.');
      setUploadPhase('idle');
    }
  };

  const handleStartImport = async () => {
    if (!pendingUploadId) return;
    setUploadPhase('starting');
    try {
      const csrfToken = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '';
      await fetch(`/waybills/import/${pendingUploadId}/start`, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrfToken, 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
      });
      setUploadPhase('done');
      setSelectedFile(null);
      setPendingUploadId(null);
      setValidationResult(null);
      if (fileInputRef.current) fileInputRef.current.value = '';
      router.reload({ only: ['uploads', 'stats'] });
    } catch {
      setUploadError('Failed to start import. Please try again.');
      setUploadPhase('preview');
    }
  };

  const handleCancelUpload = () => {
    xhrRef.current?.abort();
    xhrRef.current = null;
    setTransferPct(null);
    setUploadPhase('idle');
  };

  const handleResetPreview = () => {
    setUploadPhase('idle');
    setValidationResult(null);
    setPendingUploadId(null);
    setSelectedFile(null);
    setUploadError(null);
    if (fileInputRef.current) fileInputRef.current.value = '';
  };

  const handleRetry = (uploadId: number) => {
    router.post(`/waybills/import/${uploadId}/retry`);
  };

  const handleCancel = (uploadId: number) => {
    if (confirm('Stop this import? Already-imported waybills will remain in the system.')) {
      router.post(`/waybills/import/${uploadId}/cancel`);
    }
  };

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'completed':
        return <Badge className="bg-green-100 text-green-800 border-green-200"><CheckCircle className="w-3 h-3 mr-1" /> Completed</Badge>;
      case 'completed_with_errors':
        return <Badge className="bg-amber-100 text-amber-800 border-amber-200"><AlertCircle className="w-3 h-3 mr-1" /> Completed with Errors</Badge>;
      case 'processing':
        return <Badge className="bg-blue-100 text-blue-800 border-blue-200"><Loader2 className="w-3 h-3 mr-1 animate-spin" /> Processing</Badge>;
      case 'validating':
        return <Badge className="bg-yellow-100 text-yellow-800 border-yellow-200"><Loader2 className="w-3 h-3 mr-1 animate-spin" /> Validating</Badge>;
      case 'ready_to_process':
        return <Badge className="bg-blue-100 text-blue-800 border-blue-200"><Clock className="w-3 h-3 mr-1" /> Ready</Badge>;
      case 'failed':
        return <Badge className="bg-red-100 text-red-800 border-red-200"><XCircle className="w-3 h-3 mr-1" /> Failed</Badge>;
      case 'validation_failed':
        return <Badge className="bg-red-100 text-red-800 border-red-200"><XCircle className="w-3 h-3 mr-1" /> Validation Failed</Badge>;
      case 'cancelled':
        return <Badge className="bg-orange-100 text-orange-800 border-orange-200"><Ban className="w-3 h-3 mr-1" /> Cancelled</Badge>;
      default:
        return <Badge className="bg-gray-100 text-gray-800 border-gray-200"><Clock className="w-3 h-3 mr-1" /> Queued</Badge>;
    }
  };

  const getRowSummary = (upload: UploadRecord) => {
    const live = liveProgress[upload.id];
    const isActive = upload.status === 'processing' || upload.status === 'pending';

    if (isActive && live) {
      return {
        processed: live.processed_rows ?? 0,
        total: live.total_rows ?? 0,
        success: live.success_rows,
        inserted: live.inserted_rows ?? 0,
        updated: live.updated_rows ?? 0,
        skipped: live.skipped_rows ?? 0,
        errors: live.error_rows,
        pct: live.total_rows > 0 ? Math.min(Math.round((live.processed_rows / live.total_rows) * 100), 99) : null,
      };
    }

    return {
      processed: upload.processed_rows,
      total: upload.total_rows,
      success: upload.success_rows,
      inserted: upload.inserted_rows ?? 0,
      updated: upload.updated_rows ?? 0,
      skipped: upload.skipped_rows ?? 0,
      errors: upload.error_rows,
      pct: null,
    };
  };

  const isUploading = uploadPhase === 'uploading';
  const isFormDisabled = isUploading || uploadPhase === 'validating' || uploadPhase === 'preview' || uploadPhase === 'starting' || uploadPhase === 'done';

  return (
    <AppLayout>
      <Head title="Import Waybills" />

      <div className="space-y-6">
        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div>
            <h1 className="text-2xl font-bold tracking-tight">Import Waybills</h1>
            <p className="text-muted-foreground">
              Upload Excel files from J&T or Flash courier to import waybill data
            </p>
          </div>
          <Button variant="outline" asChild>
            <a href={`/waybills/import/template?courier=${selectedCourier}`}>
              <Download className="mr-2 h-4 w-4" />
              Download Template
            </a>
          </Button>
        </div>

        <div className="grid gap-4 md:grid-cols-4">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium">Total Uploads</CardTitle>
              <Upload className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{stats.total_uploads}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium">Waybills Imported</CardTitle>
              <FileSpreadsheet className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{stats.total_imported.toLocaleString()}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium">Processing</CardTitle>
              <Clock className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{stats.pending_uploads}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium">Recent Errors</CardTitle>
              <AlertCircle className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-red-600">{stats.recent_errors}</div>
            </CardContent>
          </Card>
        </div>

        <div className="grid gap-6 lg:grid-cols-3">
          <div className="lg:col-span-1 space-y-4">
            <Card>
              <CardHeader>
                <CardTitle>Upload File</CardTitle>
                <CardDescription>Select courier, import type, and upload Excel file</CardDescription>
              </CardHeader>
              <CardContent>
                <form onSubmit={handleSubmit} className="space-y-4">
                  <div className="space-y-2">
                    <label className="text-sm font-medium">Courier Provider</label>
                    <Select value={selectedCourier} onValueChange={setSelectedCourier} disabled={isFormDisabled}>
                      <SelectTrigger>
                        <SelectValue placeholder="Select courier" />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="jnt">J&T Express</SelectItem>
                        <SelectItem value="flash">Flash Express</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>

                  <div className="space-y-2">
                    <label className="text-sm font-medium">Import Type</label>
                    <Select value={selectedImportType} onValueChange={setSelectedImportType} disabled={isFormDisabled}>
                      <SelectTrigger>
                        <SelectValue placeholder="Select import type" />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="new_waybill">New Waybill Import</SelectItem>
                        <SelectItem value="status_update">Status Update</SelectItem>
                        <SelectItem value="cod_update">COD Update</SelectItem>
                        <SelectItem value="full_update">Full Data Update</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>

                  <div
                    className={`relative border-2 border-dashed rounded-lg p-8 text-center transition-colors ${
                      isUploading || uploadPhase === 'validating'
                        ? 'border-blue-300 bg-blue-50 pointer-events-none'
                        : uploadPhase === 'preview' || uploadPhase === 'starting' || uploadPhase === 'done'
                        ? 'border-gray-200 bg-gray-50 pointer-events-none'
                        : dragOver
                        ? 'border-primary bg-primary/5'
                        : selectedFile
                        ? 'border-green-500 bg-green-50'
                        : 'border-muted-foreground/25 hover:border-primary'
                    }`}
                    onDragOver={(e) => { e.preventDefault(); if (!isFormDisabled) setDragOver(true); }}
                    onDragLeave={() => setDragOver(false)}
                    onDrop={handleDrop}
                  >
                    {!isFormDisabled && (
                      <input
                        ref={fileInputRef}
                        type="file"
                        accept=".xlsx,.xls,.csv"
                        onChange={(e) => { const f = e.target.files?.[0]; if (f) handleFileSelect(f); }}
                        className="absolute inset-0 w-full h-full opacity-0 cursor-pointer"
                      />
                    )}

                    {isUploading ? (
                      <div className="space-y-3">
                        <Loader2 className="mx-auto h-10 w-10 text-blue-500 animate-spin" />
                        <p className="font-medium text-blue-700">Uploading file...</p>
                        <div className="space-y-1">
                          <Progress value={transferPct ?? 0} className="h-2" />
                          <p className="text-sm text-blue-600 font-medium">{transferPct}%</p>
                        </div>
                        <Button type="button" variant="ghost" size="sm" onClick={handleCancelUpload}
                          className="text-red-600 hover:text-red-700">
                          <X className="h-3 w-3 mr-1" /> Cancel
                        </Button>
                      </div>
                    ) : uploadPhase === 'validating' ? (
                      <div className="space-y-3">
                        <Loader2 className="mx-auto h-10 w-10 text-yellow-500 animate-spin" />
                        <p className="font-medium text-yellow-700">Validating file...</p>
                      </div>
                    ) : uploadPhase === 'preview' || uploadPhase === 'starting' || uploadPhase === 'done' ? (
                      <div className="space-y-2">
                        <CheckCircle className="mx-auto h-10 w-10 text-green-600" />
                        <p className="font-medium text-green-700">{selectedFile?.name ?? 'File uploaded'}</p>
                        <p className="text-sm text-muted-foreground">Validation complete</p>
                      </div>
                    ) : selectedFile ? (
                      <div className="space-y-2">
                        <FileSpreadsheet className="mx-auto h-10 w-10 text-green-600" />
                        <p className="font-medium text-green-700 break-all">{selectedFile.name}</p>
                        <p className="text-sm text-muted-foreground">
                          {(selectedFile.size / 1024 / 1024).toFixed(2)} MB
                        </p>
                        <Button type="button" variant="ghost" size="sm"
                          onClick={(e) => { e.stopPropagation(); setSelectedFile(null); if (fileInputRef.current) fileInputRef.current.value = ''; }}>
                          Remove
                        </Button>
                      </div>
                    ) : (
                      <div className="space-y-2">
                        <Upload className="mx-auto h-10 w-10 text-muted-foreground" />
                        <p className="font-medium">Drop file here or click to browse</p>
                        <p className="text-sm text-muted-foreground">Supports XLSX, XLS, CSV (max 100MB)</p>
                      </div>
                    )}
                  </div>

                  {uploadError && (
                    <div className="flex items-start gap-2 p-3 bg-red-50 border border-red-200 rounded-md">
                      <AlertCircle className="h-4 w-4 text-red-500 mt-0.5 shrink-0" />
                      <p className="text-sm text-red-700">{uploadError}</p>
                    </div>
                  )}

                  {uploadPhase === 'idle' || uploadPhase === 'uploading' ? (
                    <Button type="submit" className="w-full" disabled={!selectedFile || isUploading}>
                      {isUploading ? (
                        <><Loader2 className="mr-2 h-4 w-4 animate-spin" />Uploading {transferPct}%</>
                      ) : (
                        <><Upload className="mr-2 h-4 w-4" />Upload File</>
                      )}
                    </Button>
                  ) : null}
                </form>
              </CardContent>
            </Card>

            {uploadPhase === 'preview' && validationResult && (
              <Card className={validationResult.valid ? 'border-green-200' : 'border-red-200'}>
                <CardHeader className="pb-3">
                  <CardTitle className={`text-base flex items-center gap-2 ${validationResult.valid ? 'text-green-700' : 'text-red-700'}`}>
                    {validationResult.valid
                      ? <><CheckCircle className="h-5 w-5" /> Validation Passed</>
                      : <><XCircle className="h-5 w-5" /> Validation Failed</>
                    }
                  </CardTitle>
                </CardHeader>
                <CardContent className="space-y-4">
                  <div className="text-sm space-y-1">
                    <p><span className="font-medium">Rows detected:</span> {typeof validationResult.total_rows_detected === 'number' ? validationResult.total_rows_detected.toLocaleString() : validationResult.total_rows_detected}</p>
                    {validationResult.duplicate_waybills_count > 0 && (
                      <p className="text-amber-700"><span className="font-medium">Duplicates:</span> {validationResult.duplicate_waybills_count.toLocaleString()}</p>
                    )}
                  </div>

                  {validationResult.warnings.length > 0 && (
                    <div className="space-y-1">
                      <p className="text-xs font-medium text-amber-700">Warnings</p>
                      <ul className="text-xs text-amber-700 space-y-0.5">
                        {validationResult.warnings.map((w, i) => <li key={i}>• {w}</li>)}
                      </ul>
                    </div>
                  )}

                  {validationResult.errors && validationResult.errors.length > 0 && (
                    <div className="space-y-1">
                      <p className="text-xs font-medium text-red-700">Errors</p>
                      <ul className="text-xs text-red-700 space-y-0.5">
                        {validationResult.errors.map((e, i) => <li key={i}>• {e}</li>)}
                      </ul>
                    </div>
                  )}

                  {validationResult.missing_headers.length > 0 && (
                    <div className="space-y-1">
                      <p className="text-xs font-medium text-red-700">Missing headers</p>
                      <p className="text-xs text-red-700">{validationResult.missing_headers.join(', ')}</p>
                    </div>
                  )}

                  {validationResult.sample_rows.length > 0 && (
                    <div className="space-y-1">
                      <p className="text-xs font-medium text-muted-foreground">Preview (first {validationResult.sample_rows.length} rows)</p>
                      <div className="overflow-auto max-h-48 rounded border">
                        <table className="text-xs w-full">
                          <thead>
                            <tr className="bg-muted">
                              {validationResult.detected_columns.slice(0, 5).map((col) => (
                                <th key={col} className="px-2 py-1 text-left font-medium whitespace-nowrap">{col}</th>
                              ))}
                            </tr>
                          </thead>
                          <tbody>
                            {validationResult.sample_rows.map((row, i) => (
                              <tr key={i} className="border-t">
                                {validationResult.detected_columns.slice(0, 5).map((col) => (
                                  <td key={col} className="px-2 py-1 whitespace-nowrap truncate max-w-[120px]">
                                    {String(row[col] ?? '')}
                                  </td>
                                ))}
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </div>
                    </div>
                  )}

                  <div className="flex gap-2 pt-1">
                    {validationResult.valid && (
                      <Button className="flex-1" onClick={handleStartImport}>
                        <CheckCircle className="mr-2 h-4 w-4" />Start Import
                      </Button>
                    )}
                    <Button variant="outline" className="flex-1" onClick={handleResetPreview}>
                      {validationResult.valid ? 'Cancel' : 'Re-upload'}
                    </Button>
                  </div>
                </CardContent>
              </Card>
            )}
          </div>

          <Card className="lg:col-span-2">
            <CardHeader>
              <CardTitle>Upload History</CardTitle>
              <CardDescription>Recent file uploads and their status</CardDescription>
            </CardHeader>
            <CardContent>
              {uploads.data.length === 0 ? (
                <div className="text-center py-8">
                  <FileSpreadsheet className="mx-auto h-12 w-12 text-muted-foreground/50" />
                  <p className="mt-4 text-muted-foreground">No uploads yet</p>
                </div>
              ) : (
                <div className="space-y-3">
                  {uploads.data.map((upload) => {
                    const { processed, total, inserted, updated, skipped, errors: errCount, pct } = getRowSummary(upload);
                    const live = liveProgress[upload.id];
                    const isActive = upload.status === 'processing' || upload.status === 'pending';
                    const displayTotal = total > 0 ? total : processed;

                    return (
                      <div key={upload.id} className="border rounded-lg p-4 space-y-3">
                        <div className="flex items-start justify-between gap-3">
                          <div className="flex items-center gap-3 min-w-0">
                            <FileSpreadsheet className="h-8 w-8 text-green-600 shrink-0" />
                            <div className="min-w-0">
                              <div className="flex items-center gap-2 flex-wrap">
                                <p className="font-medium truncate">{upload.original_filename}</p>
                                {upload.courier && (
                                  <Badge variant="outline" className="text-xs uppercase shrink-0">{upload.courier}</Badge>
                                )}
                                {upload.import_type && (
                                  <Badge variant="outline" className="text-xs shrink-0">{formatImportType(upload.import_type)}</Badge>
                                )}
                              </div>
                              <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-sm text-muted-foreground">
                                {displayTotal > 0 && (
                                  <span>{displayTotal.toLocaleString()} rows</span>
                                )}
                                {inserted > 0 && (
                                  <><span>|</span><span className="text-green-600">{inserted.toLocaleString()} ins</span></>
                                )}
                                {updated > 0 && (
                                  <><span>|</span><span className="text-blue-600">{updated.toLocaleString()} upd</span></>
                                )}
                                {skipped > 0 && (
                                  <><span>|</span><span className="text-yellow-600">{skipped.toLocaleString()} skp</span></>
                                )}
                                {errCount > 0 && (
                                  <><span>|</span><span className="text-red-600">{errCount.toLocaleString()} err</span></>
                                )}
                              </div>
                              <p className="text-xs text-muted-foreground">
                                {formatDateTime(upload.created_at)}
                                {upload.uploaded_by && ` by ${upload.uploaded_by.name}`}
                              </p>
                            </div>
                          </div>

                          <div className="flex items-center gap-2 shrink-0">
                            {getStatusBadge(isActive && live ? live.status : upload.status)}
                            {isActive && (
                              <Button variant="outline" size="sm"
                                className="text-red-600 hover:text-red-700 hover:bg-red-50"
                                onClick={() => handleCancel(upload.id)} title="Stop import">
                                <StopCircle className="h-4 w-4" />
                              </Button>
                            )}
                            {upload.status === 'failed' && (
                              <Button variant="outline" size="sm" onClick={() => handleRetry(upload.id)} title="Retry">
                                <RefreshCw className="h-4 w-4" />
                              </Button>
                            )}
                            <Button variant="ghost" size="sm"
                              onClick={() => router.visit(`/waybills/import/${upload.id}`)} title="View details">
                              <Eye className="h-4 w-4" />
                            </Button>
                          </div>
                        </div>

                        {isActive && (
                          <div className="space-y-1.5">
                            <div className="flex justify-between text-xs text-muted-foreground">
                              <span>
                                {live && live.processed_rows > 0
                                  ? `${live.processed_rows.toLocaleString()} rows processed`
                                  : 'Queued — waiting to start…'}
                              </span>
                              {pct !== null && <span>{pct}%</span>}
                            </div>
                            <Progress
                              value={pct ?? 0}
                              className={`h-1.5 ${pct === null ? 'animate-pulse' : ''}`}
                            />
                          </div>
                        )}

                        {upload.status === 'failed' && upload.errors && (
                          <div className="text-xs text-red-600 bg-red-50 border border-red-100 rounded px-3 py-2 space-y-0.5">
                            {Array.isArray(upload.errors)
                              ? upload.errors.slice(0, 2).map((e, i) => (
                                  <p key={i}>Row {e.row}: {e.error}</p>
                                ))
                              : <p>{(upload.errors as { message?: string })?.message ?? 'Unknown error'}</p>}
                          </div>
                        )}
                      </div>
                    );
                  })}

                  {uploads.links.length > 3 && (
                    <div className="flex justify-center gap-1 pt-2">
                      {uploads.links.map((link, index) => (
                        <Button key={index} variant={link.active ? 'default' : 'outline'} size="sm"
                          disabled={!link.url} onClick={() => link.url && router.visit(link.url)}
                          dangerouslySetInnerHTML={{ __html: link.label }} />
                      ))}
                    </div>
                  )}
                </div>
              )}
            </CardContent>
          </Card>
        </div>
      </div>
    </AppLayout>
  );
}
