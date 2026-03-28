import { useState, useRef, useEffect } from 'react';
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
} from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { api } from '@/lib/api';
import type { UploadRecord } from '@/types';

export default function Import() {
  const fileInputRef = useRef<HTMLInputElement>(null);
  const [selectedFile, setSelectedFile] = useState<File | null>(null);
  const [selectedCourier, setSelectedCourier] = useState('jnt');
  const [dragOver, setDragOver] = useState(false);
  const [isUploading, setIsUploading] = useState(false);
  const [uploadError, setUploadError] = useState<string | null>(null);
  const [uploads, setUploads] = useState<UploadRecord[]>([]);
  const [stats, setStats] = useState({ total_uploads: 0, total_imported: 0, pending_uploads: 0, recent_errors: 0 });
  const [loading, setLoading] = useState(true);
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

  useEffect(() => {
    fetchUploads();
    const interval = setInterval(fetchUploads, 15000); // Poll for processing status
    return () => clearInterval(interval);
  }, []);

  const isValidFile = (file: File): boolean => {
    return file.name.match(/\.(xlsx|xls|csv)$/i) !== null;
  };

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

    setIsUploading(true);
    setUploadError(null);

    try {
      await api.uploadWaybills(selectedFile, selectedCourier);
      setSelectedFile(null);
      if (fileInputRef.current) fileInputRef.current.value = '';
      fetchUploads();
    } catch (err: unknown) {
      const axiosErr = err as { response?: { data?: { message?: string } } };
      setUploadError(axiosErr?.response?.data?.message || 'Upload failed. Please try again.');
    } finally {
      setIsUploading(false);
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
        return <Badge className="bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200"><CheckCircle className="w-3 h-3 mr-1" /> Completed</Badge>;
      case 'processing':
        return <Badge className="bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200"><Loader2 className="w-3 h-3 mr-1 animate-spin" /> Processing</Badge>;
      case 'failed':
        return <Badge className="bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200"><XCircle className="w-3 h-3 mr-1" /> Failed</Badge>;
      default:
        return <Badge className="bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-200"><Clock className="w-3 h-3 mr-1" /> Pending</Badge>;
    }
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center h-[60vh]">
        <Loader2 className="h-8 w-8 animate-spin text-primary" />
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Header */}
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
          <CardContent><div className="text-2xl font-bold">{stats.total_imported}</div></CardContent>
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
                  className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                  <option value="jnt">J&T Express</option>
                  <option value="flash">Flash Express</option>
                </select>
              </div>

              {/* File Drop Zone */}
              <div
                className={`relative border-2 border-dashed rounded-lg p-8 text-center transition-colors ${
                  dragOver ? 'border-primary bg-primary/5'
                    : selectedFile ? 'border-green-500 bg-green-50 dark:bg-green-950'
                    : 'border-muted-foreground/25 hover:border-primary'
                }`}
                onDragOver={(e) => { e.preventDefault(); setDragOver(true); }}
                onDragLeave={() => setDragOver(false)}
                onDrop={handleDrop}
              >
                <input
                  ref={fileInputRef}
                  type="file"
                  accept=".xlsx,.xls,.csv"
                  onChange={(e) => {
                    const file = e.target.files?.[0];
                    if (file) handleFileSelect(file);
                  }}
                  className="absolute inset-0 w-full h-full opacity-0 cursor-pointer"
                />
                {selectedFile ? (
                  <div className="space-y-2">
                    <FileSpreadsheet className="mx-auto h-10 w-10 text-green-600" />
                    <p className="font-medium text-green-700 dark:text-green-400">{selectedFile.name}</p>
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
                    <p className="text-sm text-muted-foreground">Supports XLSX, XLS, CSV</p>
                  </div>
                )}
              </div>

              {uploadError && <p className="text-sm text-red-600">{uploadError}</p>}

              <Button type="submit" className="w-full" disabled={!selectedFile || isUploading}>
                {isUploading ? (
                  <><Loader2 className="mr-2 h-4 w-4 animate-spin" /> Uploading...</>
                ) : (
                  <><Upload className="mr-2 h-4 w-4" /> Upload & Import</>
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
              <div className="space-y-4">
                {uploads.map((upload) => (
                  <div key={upload.id} className="flex items-center justify-between p-4 border rounded-lg">
                    <div className="flex items-center gap-4">
                      <FileSpreadsheet className="h-8 w-8 text-green-600 shrink-0" />
                      <div>
                        <p className="font-medium">{upload.original_filename}</p>
                        <div className="flex items-center gap-2 text-sm text-muted-foreground">
                          <span>{upload.total_rows} rows</span>
                          <span>|</span>
                          <span className="text-green-600">{upload.success_rows} success</span>
                          {upload.error_rows > 0 && (
                            <><span>|</span><span className="text-red-600">{upload.error_rows} errors</span></>
                          )}
                        </div>
                        <p className="text-xs text-muted-foreground">
                          {new Date(upload.created_at).toLocaleString()}
                          {upload.uploaded_by && ` by ${upload.uploaded_by.name}`}
                        </p>
                      </div>
                    </div>
                    <div className="flex items-center gap-2">
                      {getStatusBadge(upload.status)}
                      {upload.status === 'failed' && (
                        <Button variant="outline" size="sm" onClick={() => handleRetry(upload.id)}>
                          <RefreshCw className="h-4 w-4" />
                        </Button>
                      )}
                      <Button variant="ghost" size="sm" onClick={() => setViewingUpload(viewingUpload?.id === upload.id ? null : upload)}>
                        <Eye className="h-4 w-4" />
                      </Button>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      {/* Upload Detail */}
      {viewingUpload && viewingUpload.errors && viewingUpload.errors.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>Import Errors - {viewingUpload.original_filename}</CardTitle>
            <CardDescription>{viewingUpload.errors.length} errors found</CardDescription>
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
