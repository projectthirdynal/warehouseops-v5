import { Head, router } from '@inertiajs/react';
import { useState, useRef, useEffect, useCallback, type FormEvent, type DragEvent } from 'react';
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
  Link2,
  X,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Progress } from '@/components/ui/progress';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { formatDateTime } from '@/lib/utils';

function stripHtml(html: string): string {
  return new DOMParser().parseFromString(html, 'text/html').body?.textContent ?? html;
}

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
  status:
    | 'queued'
    | 'validating'
    | 'validation_failed'
    | 'ready_to_process'
    | 'pending'
    | 'processing'
    | 'completed'
    | 'completed_with_errors'
    | 'failed'
    | 'cancelled';
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

interface SheetConfig {
  id: number;
  courier: string;
  month: string;
  data_year: number;
  sheet_url: string | null;
  enabled: boolean;
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
  sheet_configs: SheetConfig[];
}

type UploadPhase = 'idle' | 'uploading' | 'validating' | 'preview' | 'starting' | 'done';

const COURIER_LABELS: Record<string, string> = {
  jnt: 'J&T Express',
  flash: 'Flash Express',
  spx: 'SPX Express',
};

const MONTHS = [
  'January',
  'February',
  'March',
  'April',
  'May',
  'June',
  'July',
  'August',
  'September',
  'October',
  'November',
  'December',
];

const formatImportType = (type: string | null) => {
  if (!type) return '';
  if (type === 'auto_sync') return 'Auto Sync';
  if (type === 'google_sync') return 'Google Sync';
  return type
    .split('_')
    .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
    .join(' ');
};

export default function WaybillImport({ uploads, stats, sheet_configs }: Props) {
  const fileInputRef = useRef<HTMLInputElement>(null);
  const xhrRef = useRef<XMLHttpRequest | null>(null);
  const pollTimerRef = useRef<ReturnType<typeof setInterval> | null>(null);

  const [selectedCourier, setSelectedCourier] = useState<string>('jnt');
  const [selectedMonth, setSelectedMonth] = useState<string>(MONTHS[new Date().getMonth()]);
  const [selectedYear, setSelectedYear] = useState<number>(new Date().getFullYear());
  const [sheetUrl, setSheetUrl] = useState<string>('');
  const [syncing, setSyncing] = useState(false);
  const [syncError, setSyncError] = useState<string | null>(null);

  const [selectedFile, setSelectedFile] = useState<File | null>(null);
  const [dragOver, setDragOver] = useState(false);
  const [uploadError, setUploadError] = useState<string | null>(null);
  const [transferPct, setTransferPct] = useState<number | null>(null);
  const [liveProgress, setLiveProgress] = useState<Record<number, UploadProgress>>({});
  const [uploadPhase, setUploadPhase] = useState<UploadPhase>('idle');
  const [validationResult, setValidationResult] = useState<ValidationResult | null>(null);
  const [pendingUploadId, setPendingUploadId] = useState<number | null>(null);

  // Load saved sheet URL for selected courier/month/year
  useEffect(() => {
    const config = sheet_configs.find(
      (c) =>
        c.courier === selectedCourier && c.month === selectedMonth && c.data_year === selectedYear
    );
    setSheetUrl(config?.sheet_url ?? '');
  }, [selectedCourier, selectedMonth, selectedYear, sheet_configs]);

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

      if (
        ['completed', 'completed_with_errors', 'failed', 'cancelled', 'validation_failed'].includes(
          data.status
        )
      ) {
        router.reload({ only: ['uploads', 'stats'] });
      }
    } catch {
      // ignore transient network errors
    }
  }, []);

  const processingIdsKey = processingIds.join(',');

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
    // eslint-disable-next-line react-hooks/exhaustive-deps -- processingIdsKey is the stable representation of processingIds
  }, [processingIdsKey, pollStatus]);

  const handleFileSelect = (file: File) => {
    setSelectedFile(file);
    setUploadError(null);
  };

  const handleDrop = (e: DragEvent<HTMLDivElement>) => {
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

  const handleSubmit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    if (!selectedFile) return;

    setUploadPhase('uploading');
    setUploadError(null);
    setValidationResult(null);
    setTransferPct(0);

    const formData = new FormData();
    formData.append('file', selectedFile);
    formData.append('courier', selectedCourier);

    let uploadId: number;
    try {
      uploadId = await new Promise<number>((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhrRef.current = xhr;

        xhr.upload.onprogress = (event) => {
          if (event.lengthComputable)
            setTransferPct(Math.round((event.loaded / event.total) * 100));
        };

        xhr.onload = () => {
          xhrRef.current = null;
          setTransferPct(null);
          if (xhr.status >= 200 && xhr.status < 300) {
            try {
              const body = JSON.parse(xhr.responseText) as {
                upload_id?: number;
                message?: string;
                errors?: { file?: string[] };
              };
              if (typeof body.upload_id === 'number') {
                resolve(body.upload_id);
              } else {
                reject(new Error('Invalid server response.'));
              }
            } catch {
              reject(new Error('Invalid server response.'));
            }
          } else {
            try {
              const body = JSON.parse(xhr.responseText) as {
                message?: string;
                errors?: { file?: string[] };
              };
              reject(new Error(body.errors?.file?.[0] ?? body.message ?? 'Upload failed.'));
            } catch {
              reject(new Error('Upload failed.'));
            }
          }
        };

        xhr.onerror = () => {
          xhrRef.current = null;
          setTransferPct(null);
          reject(new Error('Network error.'));
        };

        const csrfToken =
          (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '';
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
      const csrfToken =
        (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '';
      const res = await fetch(`/waybills/import/${uploadId}/validate`, {
        method: 'POST',
        headers: {
          'X-CSRF-TOKEN': csrfToken,
          'X-Requested-With': 'XMLHttpRequest',
          Accept: 'application/json',
        },
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
    setUploadError(null);
    try {
      const csrfToken =
        (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '';
      const res = await fetch(`/waybills/import/${pendingUploadId}/start`, {
        method: 'POST',
        headers: {
          'X-CSRF-TOKEN': csrfToken,
          'X-Requested-With': 'XMLHttpRequest',
          Accept: 'application/json',
        },
      });
      const data = (await res.json()) as { error?: string; message?: string };
      if (!res.ok) {
        setUploadError(data.error ?? data.message ?? 'Failed to start import.');
        setUploadPhase('preview');
        return;
      }
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

  const handleSync = async () => {
    if (!sheetUrl.trim()) {
      setSyncError('Please enter a Google Sheet URL.');
      return;
    }

    setSyncing(true);
    setSyncError(null);

    try {
      const csrfToken =
        (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '';
      const res = await fetch('/waybills/import/sync-sheet', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken,
          'X-Requested-With': 'XMLHttpRequest',
          Accept: 'application/json',
        },
        body: JSON.stringify({
          courier: selectedCourier,
          sheet_url: sheetUrl.trim(),
          month: selectedMonth,
          data_year: selectedYear,
        }),
      });

      const data = (await res.json()) as { error?: string; message?: string };
      if (!res.ok) {
        setSyncError(data.error ?? data.message ?? 'Sync failed.');
        return;
      }

      // Reload to show the new upload in the list
      router.reload({ only: ['uploads', 'stats', 'sheet_configs'] });
    } catch {
      setSyncError('Network error. Please try again.');
    } finally {
      setSyncing(false);
    }
  };

  const handleSaveUrl = async () => {
    const csrfToken =
      (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '';
    await fetch('/waybills/import/save-sheet-config', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken,
        'X-Requested-With': 'XMLHttpRequest',
        Accept: 'application/json',
      },
      body: JSON.stringify({
        courier: selectedCourier,
        sheet_url: sheetUrl.trim() || null,
        month: selectedMonth,
        data_year: selectedYear,
        enabled: true,
      }),
    });
    router.reload({ only: ['sheet_configs'] });
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
        return (
          <Badge className="bg-success/10 text-success border-success/20">
            <CheckCircle className="w-3 h-3 mr-1" /> Completed
          </Badge>
        );
      case 'completed_with_errors':
        return (
          <Badge className="bg-warning/10 text-warning border-warning/20">
            <AlertCircle className="w-3 h-3 mr-1" /> Completed with Errors
          </Badge>
        );
      case 'processing':
        return (
          <Badge className="bg-info/10 text-info border-info/20">
            <Loader2 className="w-3 h-3 mr-1 animate-spin" /> Processing
          </Badge>
        );
      case 'validating':
        return (
          <Badge className="bg-warning/10 text-warning border-warning/20">
            <Loader2 className="w-3 h-3 mr-1 animate-spin" /> Validating
          </Badge>
        );
      case 'queued':
        return (
          <Badge className="bg-muted text-muted-foreground">
            <Clock className="w-3 h-3 mr-1" /> Queued
          </Badge>
        );
      case 'failed':
        return (
          <Badge className="bg-destructive/10 text-destructive border-destructive/20">
            <XCircle className="w-3 h-3 mr-1" /> Failed
          </Badge>
        );
      case 'cancelled':
        return (
          <Badge className="bg-muted text-muted-foreground">
            <Ban className="w-3 h-3 mr-1" /> Cancelled
          </Badge>
        );
      case 'validation_failed':
        return (
          <Badge className="bg-destructive/10 text-destructive border-destructive/20">
            <XCircle className="w-3 h-3 mr-1" /> Validation Failed
          </Badge>
        );
      default:
        return <Badge variant="secondary">{status}</Badge>;
    }
  };

  const getCourierBadge = (courier: string | null) => {
    if (!courier) return null;
    const colors: Record<string, string> = {
      jnt: 'bg-red-100 text-red-700 border-red-200',
      flash: 'bg-orange-100 text-orange-700 border-orange-200',
      spx: 'bg-blue-100 text-blue-700 border-blue-200',
    };
    return (
      <Badge className={colors[courier] ?? 'bg-muted text-muted-foreground'}>
        {COURIER_LABELS[courier] ?? courier.toUpperCase()}
      </Badge>
    );
  };

  const getProgressPct = (upload: UploadRecord): number => {
    const live = liveProgress[upload.id];
    const total = live?.total_rows ?? upload.total_rows;
    const processed = live?.processed_rows ?? upload.processed_rows;
    if (total === 0) return 0;
    return Math.min(100, Math.round((processed / total) * 100));
  };

  const isProcessing = (upload: UploadRecord): boolean => {
    const live = liveProgress[upload.id];
    const status = live?.status ?? upload.status;
    return ['processing', 'queued', 'validating', 'pending'].includes(status);
  };

  const isUploading = uploadPhase === 'uploading';
  const isFormDisabled =
    isUploading ||
    uploadPhase === 'validating' ||
    uploadPhase === 'preview' ||
    uploadPhase === 'starting' ||
    uploadPhase === 'done';

  return (
    <AppLayout>
      <Head title="Import Waybills" />

      <div className="space-y-4">
        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div>
            <h1 className="text-xl font-bold font-display tracking-tight">Import Waybills</h1>
            <p className="text-muted-foreground">
              Upload courier files or sync from Google Sheets. Existing waybills update
              automatically; new waybills are added.
            </p>
          </div>
          <Button variant="outline" asChild>
            <a href={`/waybills/import/template?courier=${selectedCourier}`}>
              <Download className="mr-1.5 h-4 w-4" />
              Download Template
            </a>
          </Button>
        </div>

        <div className="grid gap-4 md:grid-cols-4">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium">Total Imports</CardTitle>
              <RefreshCw className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-xl font-bold font-display">{stats.total_uploads}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium">Waybills Imported</CardTitle>
              <FileSpreadsheet className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-xl font-bold font-display">
                {stats.total_imported.toLocaleString()}
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium">Processing</CardTitle>
              <Clock className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-xl font-bold font-display">{stats.pending_uploads}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium">Recent Errors</CardTitle>
              <AlertCircle className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-xl font-bold font-display text-destructive">
                {stats.recent_errors}
              </div>
            </CardContent>
          </Card>
        </div>

        <div className="grid gap-6 lg:grid-cols-3">
          {/* Import Source Tabs */}
          <div className="lg:col-span-1 space-y-4">
            <Tabs defaultValue="file" className="w-full">
              <Card>
                <CardHeader className="pb-0">
                  <TabsList className="grid w-full grid-cols-2">
                    <TabsTrigger value="file" className="gap-2">
                      <Upload className="h-4 w-4" />
                      File Upload
                    </TabsTrigger>
                    <TabsTrigger value="sheet" className="gap-2">
                      <Link2 className="h-4 w-4" />
                      Google Sheet Sync
                    </TabsTrigger>
                  </TabsList>
                </CardHeader>
                <CardContent className="pt-6 space-y-4">
                  <TabsContent value="file" className="mt-0 space-y-4">
                    <form onSubmit={handleSubmit} className="space-y-4">
                      <div className="space-y-2">
                        <Label>Courier Provider</Label>
                        <Select
                          value={selectedCourier}
                          onValueChange={setSelectedCourier}
                          disabled={isFormDisabled}
                        >
                          <SelectTrigger>
                            <SelectValue placeholder="Select courier" />
                          </SelectTrigger>
                          <SelectContent>
                            <SelectItem value="jnt">J&T Express</SelectItem>
                            <SelectItem value="flash">Flash Express</SelectItem>
                            <SelectItem value="spx">SPX Express</SelectItem>
                          </SelectContent>
                        </Select>
                      </div>

                      <div
                        className={`relative border-2 border-dashed rounded-lg p-8 text-center transition-colors ${
                          isUploading || uploadPhase === 'validating'
                            ? 'border-info/30 bg-info/5 pointer-events-none'
                            : uploadPhase === 'preview' ||
                                uploadPhase === 'starting' ||
                                uploadPhase === 'done'
                              ? 'border-border bg-muted/50 pointer-events-none'
                              : dragOver
                                ? 'border-primary bg-primary/5'
                                : selectedFile
                                  ? 'border-success bg-success/5'
                                  : 'border-muted-foreground/25 hover:border-primary'
                        }`}
                        onDragOver={(e) => {
                          e.preventDefault();
                          if (!isFormDisabled) setDragOver(true);
                        }}
                        onDragLeave={() => setDragOver(false)}
                        onDrop={handleDrop}
                      >
                        {!isFormDisabled && (
                          <input
                            ref={fileInputRef}
                            type="file"
                            accept=".xlsx,.xls,.csv"
                            onChange={(e) => {
                              const f = e.target.files?.[0];
                              if (f) handleFileSelect(f);
                            }}
                            className="absolute inset-0 w-full h-full opacity-0 cursor-pointer"
                          />
                        )}

                        {isUploading ? (
                          <div className="space-y-3">
                            <Loader2 className="mx-auto h-10 w-10 text-info animate-spin" />
                            <p className="font-medium text-info">Uploading file...</p>
                            <div className="space-y-1">
                              <Progress value={transferPct ?? 0} className="h-2" />
                              <p className="text-sm text-info font-medium">{transferPct}%</p>
                            </div>
                            <Button
                              type="button"
                              variant="ghost"
                              size="sm"
                              onClick={handleCancelUpload}
                              className="text-destructive hover:text-destructive"
                            >
                              <X className="h-3 w-3 mr-1" /> Cancel
                            </Button>
                          </div>
                        ) : uploadPhase === 'validating' ? (
                          <div className="space-y-3">
                            <Loader2 className="mx-auto h-10 w-10 text-warning animate-spin" />
                            <p className="font-medium text-warning">Validating file...</p>
                          </div>
                        ) : uploadPhase === 'preview' ||
                          uploadPhase === 'starting' ||
                          uploadPhase === 'done' ? (
                          <div className="space-y-2">
                            <CheckCircle className="mx-auto h-10 w-10 text-success" />
                            <p className="font-medium text-success">
                              {selectedFile?.name ?? 'File uploaded'}
                            </p>
                            <p className="text-sm text-muted-foreground">Validation complete</p>
                          </div>
                        ) : selectedFile ? (
                          <div className="space-y-2">
                            <FileSpreadsheet className="mx-auto h-10 w-10 text-success" />
                            <p className="font-medium text-success break-all">
                              {selectedFile.name}
                            </p>
                            <p className="text-sm text-muted-foreground">
                              {(selectedFile.size / 1024 / 1024).toFixed(2)} MB
                            </p>
                            <Button
                              type="button"
                              variant="ghost"
                              size="sm"
                              onClick={(e) => {
                                e.stopPropagation();
                                setSelectedFile(null);
                                if (fileInputRef.current) fileInputRef.current.value = '';
                              }}
                            >
                              Remove
                            </Button>
                          </div>
                        ) : (
                          <div className="space-y-2">
                            <Upload className="mx-auto h-10 w-10 text-muted-foreground" />
                            <p className="font-medium">Drop file here or click to browse</p>
                            <p className="text-sm text-muted-foreground">
                              Supports XLSX, XLS, CSV (max 100MB)
                            </p>
                          </div>
                        )}
                      </div>

                      {uploadError && (
                        <div className="flex items-start gap-2 p-3 bg-destructive/5 border border-destructive/20 rounded-md">
                          <AlertCircle className="h-4 w-4 text-destructive mt-0.5 shrink-0" />
                          <p className="text-sm text-destructive">{uploadError}</p>
                        </div>
                      )}

                      {uploadPhase === 'idle' || uploadPhase === 'uploading' ? (
                        <Button
                          type="submit"
                          className="w-full"
                          disabled={!selectedFile || isUploading}
                        >
                          {isUploading ? (
                            <>
                              <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                              Uploading {transferPct}%
                            </>
                          ) : (
                            <>
                              <Upload className="mr-1.5 h-4 w-4" />
                              Upload &amp; Validate
                            </>
                          )}
                        </Button>
                      ) : null}
                      <p className="text-xs text-muted-foreground">
                        Existing waybills will be updated automatically. New waybills will be added.
                      </p>
                    </form>

                    {uploadPhase === 'preview' && validationResult && (
                      <Card
                        className={
                          validationResult.valid ? 'border-success/20' : 'border-destructive/20'
                        }
                      >
                        <CardHeader className="pb-3">
                          <CardTitle
                            className={`text-base flex items-center gap-2 ${
                              validationResult.valid ? 'text-success' : 'text-destructive'
                            }`}
                          >
                            {validationResult.valid ? (
                              <>
                                <CheckCircle className="h-5 w-5" /> Validation Passed
                              </>
                            ) : (
                              <>
                                <XCircle className="h-5 w-5" /> Validation Failed
                              </>
                            )}
                          </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                          <div className="text-sm space-y-1">
                            <p>
                              <span className="font-medium">Rows detected:</span>{' '}
                              {typeof validationResult.total_rows_detected === 'number'
                                ? validationResult.total_rows_detected.toLocaleString()
                                : validationResult.total_rows_detected}
                            </p>
                            {validationResult.duplicate_waybills_count > 0 && (
                              <p className="text-warning">
                                <span className="font-medium">Duplicates:</span>{' '}
                                {validationResult.duplicate_waybills_count.toLocaleString()}
                              </p>
                            )}
                          </div>

                          {validationResult.warnings.length > 0 && (
                            <div className="space-y-1">
                              <p className="text-xs font-medium text-warning">Warnings</p>
                              <ul className="text-xs text-warning space-y-0.5">
                                {validationResult.warnings.map((w, i) => (
                                  <li key={i}>• {w}</li>
                                ))}
                              </ul>
                            </div>
                          )}

                          {validationResult.errors && validationResult.errors.length > 0 && (
                            <div className="space-y-1">
                              <p className="text-xs font-medium text-destructive">Errors</p>
                              <ul className="text-xs text-destructive space-y-0.5">
                                {validationResult.errors.map((e, i) => (
                                  <li key={i}>• {e}</li>
                                ))}
                              </ul>
                            </div>
                          )}

                          {validationResult.missing_headers.length > 0 && (
                            <div className="space-y-1">
                              <p className="text-xs font-medium text-destructive">
                                Missing headers
                              </p>
                              <p className="text-xs text-destructive">
                                {validationResult.missing_headers.join(', ')}
                              </p>
                            </div>
                          )}

                          {validationResult.sample_rows.length > 0 && (
                            <div className="space-y-1">
                              <p className="text-xs font-medium text-muted-foreground">
                                Preview (first {validationResult.sample_rows.length} rows)
                              </p>
                              <div className="overflow-auto max-h-48 rounded border">
                                <table className="text-xs w-full">
                                  <thead>
                                    <tr className="bg-muted">
                                      {validationResult.detected_columns.slice(0, 5).map((col) => (
                                        <th
                                          key={col}
                                          className="px-2 py-1 text-left font-medium whitespace-nowrap"
                                        >
                                          {col}
                                        </th>
                                      ))}
                                    </tr>
                                  </thead>
                                  <tbody>
                                    {validationResult.sample_rows.map((row, i) => (
                                      <tr key={i} className="border-t">
                                        {validationResult.detected_columns
                                          .slice(0, 5)
                                          .map((col) => (
                                            <td
                                              key={col}
                                              className="px-2 py-1 whitespace-nowrap truncate max-w-[120px]"
                                            >
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
                                <CheckCircle className="mr-1.5 h-4 w-4" />
                                Start Import
                              </Button>
                            )}
                            <Button
                              variant="outline"
                              className="flex-1"
                              onClick={handleResetPreview}
                            >
                              {validationResult.valid ? 'Cancel' : 'Re-upload'}
                            </Button>
                          </div>
                        </CardContent>
                      </Card>
                    )}
                  </TabsContent>

                  <TabsContent value="sheet" className="mt-0 space-y-4">
                    <div className="space-y-2">
                      <Label>Courier Provider</Label>
                      <Select
                        value={selectedCourier}
                        onValueChange={setSelectedCourier}
                        disabled={syncing}
                      >
                        <SelectTrigger>
                          <SelectValue placeholder="Select courier" />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="jnt">J&T Express</SelectItem>
                          <SelectItem value="flash">Flash Express</SelectItem>
                          <SelectItem value="spx">SPX Express</SelectItem>
                        </SelectContent>
                      </Select>
                    </div>

                    <div className="space-y-2">
                      <Label>Month</Label>
                      <Select
                        value={selectedMonth}
                        onValueChange={setSelectedMonth}
                        disabled={syncing}
                      >
                        <SelectTrigger>
                          <SelectValue placeholder="Select month" />
                        </SelectTrigger>
                        <SelectContent>
                          {MONTHS.map((m) => (
                            <SelectItem key={m} value={m}>
                              {m}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </div>

                    <div className="space-y-2">
                      <Label>Year</Label>
                      <Select
                        value={String(selectedYear)}
                        onValueChange={(v) => setSelectedYear(Number(v))}
                        disabled={syncing}
                      >
                        <SelectTrigger>
                          <SelectValue placeholder="Select year" />
                        </SelectTrigger>
                        <SelectContent>
                          {[selectedYear - 1, selectedYear, selectedYear + 1].map((y) => (
                            <SelectItem key={y} value={String(y)}>
                              {y}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </div>

                    <div className="space-y-2">
                      <Label>Google Sheet URL</Label>
                      <Input
                        type="url"
                        placeholder="https://docs.google.com/spreadsheets/d/..."
                        value={sheetUrl}
                        onChange={(e) => setSheetUrl(e.target.value)}
                        disabled={syncing}
                      />
                      <p className="text-xs text-muted-foreground">
                        Sheet must be shared as &ldquo;Anyone with the link — Viewer&rdquo;
                      </p>
                    </div>

                    {syncError && (
                      <div className="flex items-start gap-2 rounded-md bg-destructive/10 p-3 text-sm text-destructive">
                        <AlertCircle className="h-4 w-4 mt-0.5 shrink-0" />
                        <span>{syncError}</span>
                      </div>
                    )}

                    <div className="flex gap-2">
                      <Button
                        onClick={handleSync}
                        disabled={syncing || !sheetUrl.trim()}
                        className="flex-1"
                      >
                        {syncing ? (
                          <>
                            <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                            Syncing...
                          </>
                        ) : (
                          <>
                            <RefreshCw className="h-4 w-4 mr-2" />
                            Sync Now
                          </>
                        )}
                      </Button>
                      <Button variant="outline" onClick={handleSaveUrl} disabled={syncing}>
                        Save URL
                      </Button>
                    </div>

                    <div className="rounded-md bg-info/5 border border-info/20 p-3 text-xs text-muted-foreground">
                      <p className="font-medium text-foreground mb-1">How it works:</p>
                      <ul className="space-y-1 list-disc list-inside">
                        <li>System reads the entire sheet and imports all waybill rows</li>
                        <li>
                          Re-syncing updates statuses for changed waybills
                          (inserted/updated/skipped)
                        </li>
                        <li>New rows appended to the sheet are picked up on next sync</li>
                        <li>Changing the URL for next month leaves previous data intact</li>
                      </ul>
                    </div>
                  </TabsContent>
                </CardContent>
              </Card>
            </Tabs>
          </div>

          {/* Import History */}
          <div className="lg:col-span-2 space-y-4">
            <Card>
              <CardHeader>
                <CardTitle>Import History</CardTitle>
                <CardDescription>Recent sync and import runs</CardDescription>
              </CardHeader>
              <CardContent>
                <div className="space-y-3">
                  {uploads.data.length === 0 ? (
                    <div className="text-center py-12 text-muted-foreground">
                      <FileSpreadsheet className="mx-auto h-12 w-12 mb-3 opacity-50" />
                      <p>No imports yet. Paste a Google Sheet URL to get started.</p>
                    </div>
                  ) : (
                    uploads.data.map((upload) => {
                      const live = liveProgress[upload.id];
                      const status = live?.status ?? upload.status;
                      const processing = isProcessing(upload);
                      const pct = getProgressPct(upload);

                      return (
                        <div
                          key={upload.id}
                          className="border rounded-lg p-4 space-y-3 hover:bg-muted/30 transition-colors"
                        >
                          <div className="flex items-start justify-between gap-3">
                            <div className="space-y-1 min-w-0 flex-1">
                              <div className="flex items-center gap-2 flex-wrap">
                                {getCourierBadge(upload.courier)}
                                {upload.import_type && (
                                  <Badge variant="outline" className="text-xs">
                                    {formatImportType(upload.import_type)}
                                  </Badge>
                                )}
                                {getStatusBadge(status)}
                              </div>
                              <p className="text-sm font-medium truncate">
                                {upload.original_filename}
                              </p>
                              <p className="text-xs text-muted-foreground">
                                {formatDateTime(upload.created_at)}
                                {upload.uploaded_by?.name && ` · ${upload.uploaded_by.name}`}
                              </p>
                            </div>
                            <div className="flex gap-1 shrink-0">
                              <Button variant="ghost" size="sm" asChild>
                                <a href={`/waybills/import/${upload.id}`}>
                                  <Eye className="h-3.5 w-3.5" />
                                </a>
                              </Button>
                              {processing && (
                                <Button
                                  variant="ghost"
                                  size="sm"
                                  onClick={() => handleCancel(upload.id)}
                                  className="text-destructive hover:text-destructive"
                                >
                                  <StopCircle className="h-3.5 w-3.5" />
                                </Button>
                              )}
                              {upload.status === 'failed' && (
                                <Button
                                  variant="ghost"
                                  size="sm"
                                  onClick={() => handleRetry(upload.id)}
                                >
                                  <RefreshCw className="h-3.5 w-3.5" />
                                </Button>
                              )}
                            </div>
                          </div>

                          {/* Progress bar for processing uploads */}
                          {processing && (
                            <div className="space-y-1">
                              <Progress value={pct} className="h-1.5" />
                              <div className="flex justify-between text-xs text-muted-foreground">
                                <span>
                                  {(live?.processed_rows ?? upload.processed_rows).toLocaleString()}{' '}
                                  / {(live?.total_rows ?? upload.total_rows).toLocaleString()} rows
                                </span>
                                <span>{pct}%</span>
                              </div>
                            </div>
                          )}

                          {/* Stats row */}
                          {!processing &&
                            upload.status !== 'queued' &&
                            upload.status !== 'validating' && (
                              <div className="flex gap-4 text-xs text-muted-foreground flex-wrap">
                                {upload.total_rows > 0 && (
                                  <span>Total: {upload.total_rows.toLocaleString()}</span>
                                )}
                                {upload.inserted_rows > 0 && (
                                  <span className="text-success">
                                    Inserted: {upload.inserted_rows.toLocaleString()}
                                  </span>
                                )}
                                {upload.updated_rows > 0 && (
                                  <span className="text-info">
                                    Updated: {upload.updated_rows.toLocaleString()}
                                  </span>
                                )}
                                {upload.skipped_rows > 0 && (
                                  <span className="text-muted-foreground">
                                    Skipped: {upload.skipped_rows.toLocaleString()}
                                  </span>
                                )}
                                {upload.error_rows > 0 && (
                                  <span className="text-destructive">
                                    Errors: {upload.error_rows.toLocaleString()}
                                  </span>
                                )}
                              </div>
                            )}

                          {/* Error details */}
                          {upload.status === 'failed' && upload.errors && (
                            <div className="rounded-md bg-destructive/5 p-2 text-xs text-destructive">
                              {Array.isArray(upload.errors)
                                ? upload.errors.slice(0, 3).map((e, i) => (
                                    <div key={i}>
                                      {e.row ? `Row ${e.row}: ` : ''}
                                      {e.error}
                                    </div>
                                  ))
                                : upload.errors &&
                                    typeof upload.errors === 'object' &&
                                    'message' in upload.errors
                                  ? String((upload.errors as Record<string, unknown>).message)
                                  : 'Import failed.'}
                              {Array.isArray(upload.errors) && upload.errors.length > 3 && (
                                <div className="mt-1 text-muted-foreground">
                                  ...and {upload.errors.length - 3} more errors
                                </div>
                              )}
                            </div>
                          )}

                          {/* Completed with errors - download link */}
                          {upload.status === 'completed_with_errors' && upload.error_rows > 0 && (
                            <Button variant="ghost" size="sm" asChild>
                              <a href={`/waybills/import/${upload.id}/errors/download`}>
                                <RefreshCw className="h-3 w-3 mr-1" />
                                Download Error Report
                              </a>
                            </Button>
                          )}
                        </div>
                      );
                    })
                  )}
                </div>

                {/* Pagination */}
                {uploads.links && uploads.links.some((l) => l.url !== null) && (
                  <div className="flex items-center justify-center gap-2 mt-6">
                    {uploads.links.map((link, i) => (
                      <Button
                        key={i}
                        variant={link.active ? 'default' : 'outline'}
                        size="sm"
                        disabled={!link.url}
                        onClick={() => link.url && router.visit(link.url)}
                      >
                        {stripHtml(link.label)}
                      </Button>
                    ))}
                  </div>
                )}
              </CardContent>
            </Card>
          </div>
        </div>
      </div>
    </AppLayout>
  );
}
