import { useState, useRef, useEffect, useCallback } from 'react';
import {
  Upload,
  FileSpreadsheet,
  CheckCircle,
  XCircle,
  Clock,
  RefreshCw,
  AlertCircle,
  Loader2,
  Eye,
  X,
  Ban,
} from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';
import { api } from '@/lib/api';
import type { UploadRecord } from '@/types';

interface LiveProgress {
  status: string;
  total_rows: number;
  processed_rows: number;
  success_rows: number;
  error_rows: number;
}

export default function Import() {
  const fileInputRef = useRef<HTMLInputElement>(null);
  const pollTimerRef = useRef<ReturnType<typeof setInterval> | null>(null);

  const [selectedFile, setSelectedFile] = useState<File | null>(null);
  const [selectedCourier, setSelectedCourier] = useState('jnt');
  const [dragOver, setDragOver] = useState(false);
  const [transferPct, setTransferPct] = useState<number | null>(null);
  const [uploadError, setUploadError] = useState<string | null>(null);
  const [uploads, setUploads] = useState<UploadRecord[]>([]);
  const [stats, setStats] = useState({ total_uploads: 0, total_imported: 0, pending_uploads: 0, recent_errors: 0 });
  const [loading, setLoading] = useState(true);
  const [liveProgress, setLiveProgress] = useState<Record<number, LiveProgress>>({});
  const [viewingUpload, setViewingUpload] = useState<UploadRecord | null>(null);

  const fetchUploads = async () => {
    try {
      const data = await api.getUploads();
      setUploads(data.uploads || []);
      setStats(data.stats || stats);
    } catch {
      // handled by interceptor
    } finally {
      setLoading(false);
    }
  };

  const activeIds = uploads
    .filter((u) => u.status === 'pending' || u.status === 'processing')
    .map((u) => u.id);

  const pollStatus = useCallback(async (id: number) => {
    try {
      const data = await api.getUploadStatus(id);
      const upload = data.upload ?? data;
      setLiveProgress((prev) => ({ ...prev, [id]: upload }));
      if (upload.status === 'completed' || upload.status === 'failed' || upload.status === 'cancelled') {
        fetchUploads();
      }
    } catch {
      // ignore transient errors
    }
  }, []);

  useEffect(() => {
    fetchUploads();
  }, []);

  useEffect(() => {
    if (pollTimerRef.current) clearInterval(pollTimerRef.current);

    if (activeIds.length === 0) return;

    activeIds.forEach(pollStatus);
    pollTimerRef.current = setInterval(() => {
      activeIds.forEach(pollStatus);
    }, 3000);

    return () => {
      if (pollTimerRef.current) clearInterval(pollTimerRef.current);
    };
  }, [activeIds.join(','), pollStatus]);

  const isValidFile = (file: File): boolean =>
    file.name.match(/\.(xlsx|xls|csv)$/i) !== null;

  const handleFileSelect = (file: File) => {
    if (!isValidFile(file)) {
      setUploadError('Invalid file type. Only XLSX, XLS, and CSV files are supported.');
      return;
    }
    setSelectedFile(file);
    setUploadError(null);
  };

  const handleDrop = (e: React.DragEvent) => {
    e.preventDefault();
    setDragOver(false);
    const file = e.dataTransfer.files[0];
    if (file) handleFileSelect(file);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedFile) {
      setUploadError('Please select a file to upload');
      return;
    }

    setTransferPct(0);
    setUploadError(null);

    try {
      await api.uploadWaybills(selectedFile, selectedCourier, (pct) => setTransferPct(pct));
      setSelectedFile(null);
      if (fileInputRef.current) fileInputRef.current.value = '';
      fetchUploads();
    } catch (err: unknown) {
      const axiosErr = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } };
      const msg =
        axiosErr?.response?.data?.errors
          ? Object.values(axiosErr.response.data.errors)[0]?.[0]
          : axiosErr?.response?.data?.message;
      setUploadError(msg || 'Upload failed. Please try again.');
    } finally {
      setTransferPct(null);
    }
  };

  const handleRetry = async (uploadId: number) => {
    try {
      await api.retryUpload(uploadId);
      fetchUploads();
    } catch {
      // silent
    }
  };

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'completed':
        return <Badge className="bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200"><CheckCircle className="w-3 h-3 mr-1" />Completed</Badge>;
      case 'processing':
        return <Badge className="bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200"><Loader2 className="w-3 h-3 mr-1 animate-spin" />Processing</Badge>;
      case 'failed':
        return <Badge className="bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200"><XCircle className="w-3 h-3 mr-1" />Failed</Badge>;
      case 'cancelled':
        return <Badge className="bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200"><Ban className="w-3 h-3 mr-1" />Cancelled</Badge>;
      default:
        return <Badge className="bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-200"><Clock className="w-3 h-3 mr-1" />Pending</Badge>;
    }
  };

  const getRowSummary = (upload: UploadRecord) => {
    const live = liveProgress[upload.id];
    const isActive = upload.status === 'processing' || upload.status === 'pending';

    if (isActive && live) {
      const processed = live.processed_rows ?? 0;
      const total = live.total_rows ?? 0;
      const pct = total > 0 ? Math.min(Math.round((processed / total) * 100), 99) : null;
      return { processed, total, success: live.success_rows, errors: live.error_rows, pct, liveStatus: live.status };
    }

    return {
      processed: upload.processed_rows,
      total: upload.total_rows,
      success: upload.success_rows,
      errors: upload.error_rows,
      pct: null,
      liveStatus: upload.status,
    };
  };

  const isUploading = transferPct !== null;

  if (loading) {
    return (
      <div className="flex items-center justify-center h-[60vh]">
        <Loader2 className="h-8 w-8 animate-spin text-primary" />
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Import Waybills</h1>
        <p className="text-muted-foreground">Upload Excel files from J&T or Flash courier to import waybill data</p>
      </div>

      {/* Stats */}
      <div className="grid gap-4 md:grid-cols-4">
        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Total Uploads</CardTitle>
            <Upload className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent><div className="text-2xl font-bold">{stats.total_uploads}</div></CardContent>
        </Card>
        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Waybills Imported</CardTitle>
            <FileSpreadsheet className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent><div className="text-2xl font-bold">{stats.total_imported.toLocaleString()}</div></CardContent>
        </Card>
        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Processing</CardTitle>
            <Clock className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent><div className="text-2xl font-bold">{stats.pending_uploads}</div></CardContent>
        </Card>
        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Recent Errors</CardTitle>
            <AlertCircle className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent><div className="text-2xl font-bold text-red-600">{stats.recent_errors}</div></CardContent>
        </Card>
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        {/* Upload Form */}
        <Card className="lg:col-span-1">
          <CardHeader>
            <CardTitle>Upload File</CardTitle>
            <CardDescription>Select courier and upload Excel file</CardDescription>
          </CardHeader>
          <CardContent>
            <form onSubmit={handleSubmit} className="space-y-4">
              <div className="space-y-2">
                <label className="text-sm font-medium">Courier Provider</label>
                <select
                  value={selectedCourier}
                  onChange={(e) => setSelectedCourier(e.target.value)}
                  disabled={isUploading}
                  className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50"
                >
                  <option value="jnt">J&amp;T Express</option>
                  <option value="flash">Flash Express</option>
                </select>
              </div>

              {/* Drop Zone */}
              <div
                className={`relative border-2 border-dashed rounded-lg p-8 text-center transition-colors ${
                  isUploading
                    ? 'border-blue-300 bg-blue-50 dark:bg-blue-950/30 pointer-events-none'
                    : dragOver
                    ? 'border-primary bg-primary/5'
                    : selectedFile
                    ? 'border-green-500 bg-green-50 dark:bg-green-950/30'
                    : 'border-muted-foreground/25 hover:border-primary'
                }`}
                onDragOver={(e) => { e.preventDefault(); if (!isUploading) setDragOver(true); }}
                onDragLeave={() => setDragOver(false)}
                onDrop={handleDrop}
              >
                {!isUploading && (
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
                    <p className="font-medium text-blue-700 dark:text-blue-300">Uploading file...</p>
                    <div className="space-y-1">
                      <Progress value={transferPct ?? 0} className="h-2" />
                      <p className="text-sm text-blue-600 dark:text-blue-400 font-medium">{transferPct}%</p>
                    </div>
                  </div>
                ) : selectedFile ? (
                  <div className="space-y-2">
                    <FileSpreadsheet className="mx-auto h-10 w-10 text-green-600" />
                    <p className="font-medium text-green-700 dark:text-green-400 break-all">{selectedFile.name}</p>
                    <p className="text-sm text-muted-foreground">{(selectedFile.size / 1024 / 1024).toFixed(2)} MB</p>
                    <Button type="button" variant="ghost" size="sm" onClick={(e) => {
                      e.stopPropagation();
                      setSelectedFile(null);
                      if (fileInputRef.current) fileInputRef.current.value = '';
                    }}>Remove</Button>
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
                <div className="flex items-start gap-2 p-3 bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-800 rounded-md">
                  <AlertCircle className="h-4 w-4 text-red-500 mt-0.5 shrink-0" />
                  <p className="text-sm text-red-700 dark:text-red-400">{uploadError}</p>
                </div>
              )}

              <Button type="submit" className="w-full" disabled={!selectedFile || isUploading}>
                {isUploading ? (
                  <><Loader2 className="mr-2 h-4 w-4 animate-spin" />Uploading {transferPct}%</>
                ) : (
                  <><Upload className="mr-2 h-4 w-4" />Upload &amp; Import</>
                )}
              </Button>
            </form>
          </CardContent>
        </Card>

        {/* Upload History */}
        <Card className="lg:col-span-2">
          <CardHeader>
            <CardTitle>Upload History</CardTitle>
            <CardDescription>Recent file uploads and their status</CardDescription>
          </CardHeader>
          <CardContent>
            {uploads.length === 0 ? (
              <div className="text-center py-8">
                <FileSpreadsheet className="mx-auto h-12 w-12 text-muted-foreground/50" />
                <p className="mt-4 text-muted-foreground">No uploads yet</p>
              </div>
            ) : (
              <div className="space-y-3">
                {uploads.map((upload) => {
                  const { processed, total, success, errors: errCount, pct, liveStatus } = getRowSummary(upload);
                  const live = liveProgress[upload.id];
                  const isActive = upload.status === 'processing' || upload.status === 'pending';
                  const displayTotal = total > 0 ? total : processed;

                  return (
                    <div key={upload.id} className="border rounded-lg p-4 space-y-3">
                      <div className="flex items-start justify-between gap-3">
                        <div className="flex items-center gap-3 min-w-0">
                          <FileSpreadsheet className="h-8 w-8 text-green-600 shrink-0" />
                          <div className="min-w-0">
                            <p className="font-medium truncate">{upload.original_filename}</p>
                            <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-sm text-muted-foreground">
                              {displayTotal > 0 && <span>{displayTotal.toLocaleString()} rows</span>}
                              {success > 0 && (
                                <><span>|</span><span className="text-green-600">{success.toLocaleString()} success</span></>
                              )}
                              {errCount > 0 && (
                                <><span>|</span><span className="text-red-600">{errCount.toLocaleString()} errors</span></>
                              )}
                            </div>
                            <p className="text-xs text-muted-foreground">
                              {new Date(upload.created_at).toLocaleString()}
                              {upload.uploaded_by && ` by ${upload.uploaded_by.name}`}
                            </p>
                          </div>
                        </div>

                        <div className="flex items-center gap-2 shrink-0">
                          {getStatusBadge(isActive && live ? liveStatus : upload.status)}
                          {upload.status === 'failed' && (
                            <Button variant="outline" size="sm" onClick={() => handleRetry(upload.id)} title="Retry">
                              <RefreshCw className="h-4 w-4" />
                            </Button>
                          )}
                          <Button variant="ghost" size="sm"
                            onClick={() => setViewingUpload(viewingUpload?.id === upload.id ? null : upload)}
                            title="View details">
                            <Eye className="h-4 w-4" />
                          </Button>
                        </div>
                      </div>

                      {/* Live progress bar for active items */}
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
                            className={`h-1.5 ${!live || live.processed_rows === 0 ? '[&>div]:animate-pulse' : ''}`}
                          />
                        </div>
                      )}

                      {/* Error snippet for failed uploads */}
                      {upload.status === 'failed' && upload.errors && (
                        <div className="text-xs text-red-600 bg-red-50 dark:bg-red-950/30 border border-red-100 dark:border-red-900 rounded px-3 py-2 space-y-0.5">
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
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      {/* Upload Detail */}
      {viewingUpload && viewingUpload.errors && viewingUpload.errors.length > 0 && (
        <Card>
          <CardHeader className="flex flex-row items-center justify-between">
            <div>
              <CardTitle>Import Errors — {viewingUpload.original_filename}</CardTitle>
              <CardDescription>{viewingUpload.errors.length} errors found</CardDescription>
            </div>
            <Button variant="ghost" size="sm" onClick={() => setViewingUpload(null)}>
              <X className="h-4 w-4" />
            </Button>
          </CardHeader>
          <CardContent>
            <div className="space-y-2 max-h-[300px] overflow-y-auto">
              {viewingUpload.errors.map((err, i) => (
                <div key={i} className="flex items-start gap-2 text-sm border-b pb-2">
                  <Badge variant="destructive" className="shrink-0">Row {err.row}</Badge>
                  <span className="text-muted-foreground">{err.error}</span>
                </div>
              ))}
            </div>
          </CardContent>
        </Card>
      )}
    </div>
  );
}
