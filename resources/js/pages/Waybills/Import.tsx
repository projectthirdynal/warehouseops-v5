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
  total_rows: number;
  processed_rows: number;
  success_rows: number;
  error_rows: number;
  status: 'pending' | 'processing' | 'completed' | 'failed' | 'cancelled';
  errors: Array<{ row: number; error: string }> | null;
  uploaded_by: { name: string } | null;
  created_at: string;
}

interface UploadProgress {
  id: number;
  status: string;
  total_rows: number;
  processed_rows: number;
  success_rows: number;
  error_rows: number;
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

export default function WaybillImport({ uploads, stats }: Props) {
  const fileInputRef = useRef<HTMLInputElement>(null);
  const xhrRef = useRef<XMLHttpRequest | null>(null);
  const pollTimerRef = useRef<ReturnType<typeof setInterval> | null>(null);

  const [selectedFile, setSelectedFile] = useState<File | null>(null);
  const [selectedCourier, setSelectedCourier] = useState<string>('jnt');
  const [dragOver, setDragOver] = useState(false);
  const [uploadError, setUploadError] = useState<string | null>(null);

  // Upload phase: null = idle, 0-100 = transferring file to server
  const [transferPct, setTransferPct] = useState<number | null>(null);

  // Live progress for active processing uploads (keyed by upload id)
  const [liveProgress, setLiveProgress] = useState<Record<number, UploadProgress>>({});

  // Which upload ids are currently processing (from server-rendered data)
  const processingIds = uploads.data
    .filter((u) => u.status === 'pending' || u.status === 'processing')
    .map((u) => u.id);

  // Poll status for any processing upload
  const pollStatus = useCallback(async (id: number) => {
    try {
      const res = await fetch(`/waybills/import/${id}/status`, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      });
      if (!res.ok) return;
      const data: UploadProgress = await res.json();
      setLiveProgress((prev) => ({ ...prev, [id]: data }));

      // Stop polling when done
      if (data.status === 'completed' || data.status === 'failed' || data.status === 'cancelled') {
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

    // Poll immediately then every 3 seconds
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

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedFile) {
      setUploadError('Please select a file to upload');
      return;
    }

    setTransferPct(0);
    setUploadError(null);

    const formData = new FormData();
    formData.append('file', selectedFile);
    formData.append('courier', selectedCourier);

    // Get CSRF token from meta tag
    const csrfToken = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '';

    const xhr = new XMLHttpRequest();
    xhrRef.current = xhr;

    xhr.upload.onprogress = (event) => {
      if (event.lengthComputable) {
        setTransferPct(Math.round((event.loaded / event.total) * 100));
      }
    };

    xhr.onload = () => {
      xhrRef.current = null;
      setTransferPct(null);
      setSelectedFile(null);
      if (fileInputRef.current) fileInputRef.current.value = '';

      if (xhr.status >= 200 && xhr.status < 300) {
        router.reload({ only: ['uploads', 'stats'] });
      } else {
        try {
          const body = JSON.parse(xhr.responseText);
          setUploadError(body?.errors?.file?.[0] ?? body?.message ?? 'Upload failed. Please try again.');
        } catch {
          setUploadError('Upload failed. Please try again.');
        }
      }
    };

    xhr.onerror = () => {
      xhrRef.current = null;
      setTransferPct(null);
      setUploadError('Network error. Please check your connection and try again.');
    };

    xhr.open('POST', '/waybills/import');
    xhr.setRequestHeader('X-CSRF-TOKEN', csrfToken);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.send(formData);
  };

  const handleCancelUpload = () => {
    xhrRef.current?.abort();
    xhrRef.current = null;
    setTransferPct(null);
  };

  const handleRetry = (uploadId: number) => {
    router.post(`/waybills/import/${uploadId}/retry`);
  };

  const handleCancel = (uploadId: number) => {
    if (confirm('Stop this import? Waybills already imported from this upload will be removed.')) {
      router.post(`/waybills/import/${uploadId}/cancel`);
    }
  };

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'completed':
        return <Badge className="bg-green-100 text-green-800 border-green-200"><CheckCircle className="w-3 h-3 mr-1" /> Completed</Badge>;
      case 'processing':
        return <Badge className="bg-blue-100 text-blue-800 border-blue-200"><Loader2 className="w-3 h-3 mr-1 animate-spin" /> Processing</Badge>;
      case 'failed':
        return <Badge className="bg-red-100 text-red-800 border-red-200"><XCircle className="w-3 h-3 mr-1" /> Failed</Badge>;
      case 'cancelled':
        return <Badge className="bg-orange-100 text-orange-800 border-orange-200"><Ban className="w-3 h-3 mr-1" /> Cancelled</Badge>;
      default:
        return <Badge className="bg-gray-100 text-gray-800 border-gray-200"><Clock className="w-3 h-3 mr-1" /> Pending</Badge>;
    }
  };

  const getRowSummary = (upload: UploadRecord) => {
    const live = liveProgress[upload.id];
    const isActive = upload.status === 'processing' || upload.status === 'pending';

    if (isActive && live) {
      const processed = live.processed_rows ?? 0;
      const total = live.total_rows ?? 0;
      const pct = total > 0 ? Math.min(Math.round((processed / total) * 100), 99) : null;
      return { processed, total, success: live.success_rows, errors: live.error_rows, pct };
    }

    return {
      processed: upload.processed_rows,
      total: upload.total_rows,
      success: upload.success_rows,
      errors: upload.error_rows,
      pct: null,
    };
  };

  const isUploading = transferPct !== null;

  return (
    <AppLayout>
      <Head title="Import Waybills" />

      <div className="space-y-6">
        {/* Header */}
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

        {/* Stats */}
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
                  <Select value={selectedCourier} onValueChange={setSelectedCourier} disabled={isUploading}>
                    <SelectTrigger>
                      <SelectValue placeholder="Select courier" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="jnt">J&T Express</SelectItem>
                      <SelectItem value="flash">Flash Express</SelectItem>
                    </SelectContent>
                  </Select>
                </div>

                {/* Drop Zone */}
                <div
                  className={`relative border-2 border-dashed rounded-lg p-8 text-center transition-colors ${
                    isUploading
                      ? 'border-blue-300 bg-blue-50 pointer-events-none'
                      : dragOver
                      ? 'border-primary bg-primary/5'
                      : selectedFile
                      ? 'border-green-500 bg-green-50'
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

                <Button type="submit" className="w-full" disabled={!selectedFile || isUploading}>
                  {isUploading ? (
                    <><Loader2 className="mr-2 h-4 w-4 animate-spin" />Uploading {transferPct}%</>
                  ) : (
                    <><Upload className="mr-2 h-4 w-4" />Upload & Import</>
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
              {uploads.data.length === 0 ? (
                <div className="text-center py-8">
                  <FileSpreadsheet className="mx-auto h-12 w-12 text-muted-foreground/50" />
                  <p className="mt-4 text-muted-foreground">No uploads yet</p>
                </div>
              ) : (
                <div className="space-y-3">
                  {uploads.data.map((upload) => {
                    const { processed, total, success, errors: errCount, pct } = getRowSummary(upload);
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
                                {displayTotal > 0 && (
                                  <span>{displayTotal.toLocaleString()} rows</span>
                                )}
                                {success > 0 && (
                                  <><span>|</span><span className="text-green-600">{success.toLocaleString()} success</span></>
                                )}
                                {errCount > 0 && (
                                  <><span>|</span><span className="text-red-600">{errCount.toLocaleString()} errors</span></>
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

                        {/* Live progress bar for active uploads */}
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

                        {/* Failed error snippet */}
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

                  {/* Pagination */}
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
