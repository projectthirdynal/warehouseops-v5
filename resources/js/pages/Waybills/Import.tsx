import { Head, router } from '@inertiajs/react';
import { useState, useRef } from 'react';
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
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';

interface UploadRecord {
  id: number;
  filename: string;
  original_filename: string;
  total_rows: number;
  processed_rows: number;
  success_rows: number;
  error_rows: number;
  status: 'pending' | 'processing' | 'completed' | 'failed';
  errors: Array<{ row: number; error: string }> | null;
  uploaded_by: { name: string } | null;
  created_at: string;
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
  const [selectedFile, setSelectedFile] = useState<File | null>(null);
  const [selectedCourier, setSelectedCourier] = useState<string>('jnt');
  const [dragOver, setDragOver] = useState(false);
  const [isUploading, setIsUploading] = useState(false);
  const [uploadError, setUploadError] = useState<string | null>(null);

  const handleFileSelect = (file: File) => {
    setSelectedFile(file);
    setUploadError(null);
  };

  const handleDrop = (e: React.DragEvent) => {
    e.preventDefault();
    setDragOver(false);

    const file = e.dataTransfer.files[0];
    if (file && isValidFile(file)) {
      handleFileSelect(file);
    }
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

    setIsUploading(true);
    setUploadError(null);

    router.post('/waybills/import', {
      file: selectedFile,
      courier: selectedCourier,
    }, {
      forceFormData: true,
      onSuccess: () => {
        setSelectedFile(null);
        setIsUploading(false);
        if (fileInputRef.current) {
          fileInputRef.current.value = '';
        }
      },
      onError: (errors) => {
        setIsUploading(false);
        setUploadError(errors.file || 'Upload failed. Please try again.');
      },
    });
  };

  const handleRetry = (uploadId: number) => {
    router.post(`/waybills/import/${uploadId}/retry`);
  };

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'completed':
        return <Badge className="bg-green-100 text-green-800"><CheckCircle className="w-3 h-3 mr-1" /> Completed</Badge>;
      case 'processing':
        return <Badge className="bg-blue-100 text-blue-800"><Loader2 className="w-3 h-3 mr-1 animate-spin" /> Processing</Badge>;
      case 'failed':
        return <Badge className="bg-red-100 text-red-800"><XCircle className="w-3 h-3 mr-1" /> Failed</Badge>;
      default:
        return <Badge className="bg-gray-100 text-gray-800"><Clock className="w-3 h-3 mr-1" /> Pending</Badge>;
    }
  };

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
          <div className="flex gap-2">
            <Button variant="outline" asChild>
              <a href={`/waybills/import/template?courier=${selectedCourier}`}>
                <Download className="mr-2 h-4 w-4" />
                Download Template
              </a>
            </Button>
          </div>
        </div>

        {/* Stats Cards */}
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
              <div className="text-2xl font-bold">{stats.total_imported}</div>
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
              <CardDescription>
                Select courier and upload Excel file
              </CardDescription>
            </CardHeader>
            <CardContent>
              <form onSubmit={handleSubmit} className="space-y-4">
                {/* Courier Selection */}
                <div className="space-y-2">
                  <label className="text-sm font-medium">Courier Provider</label>
                  <Select value={selectedCourier} onValueChange={setSelectedCourier}>
                    <SelectTrigger>
                      <SelectValue placeholder="Select courier" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="jnt">J&T Express</SelectItem>
                      <SelectItem value="flash">Flash Express</SelectItem>
                    </SelectContent>
                  </Select>
                </div>

                {/* File Drop Zone */}
                <div
                  className={`relative border-2 border-dashed rounded-lg p-8 text-center transition-colors ${
                    dragOver
                      ? 'border-primary bg-primary/5'
                      : selectedFile
                      ? 'border-green-500 bg-green-50'
                      : 'border-muted-foreground/25 hover:border-primary'
                  }`}
                  onDragOver={(e) => {
                    e.preventDefault();
                    setDragOver(true);
                  }}
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
                      <p className="font-medium text-green-700">{selectedFile.name}</p>
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
                  <p className="text-sm text-red-600">{uploadError}</p>
                )}

                <Button
                  type="submit"
                  className="w-full"
                  disabled={!selectedFile || isUploading}
                >
                  {isUploading ? (
                    <>
                      <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                      Uploading...
                    </>
                  ) : (
                    <>
                      <Upload className="mr-2 h-4 w-4" />
                      Upload & Import
                    </>
                  )}
                </Button>
              </form>
            </CardContent>
          </Card>

          {/* Upload History */}
          <Card className="lg:col-span-2">
            <CardHeader>
              <CardTitle>Upload History</CardTitle>
              <CardDescription>
                Recent file uploads and their status
              </CardDescription>
            </CardHeader>
            <CardContent>
              {uploads.data.length === 0 ? (
                <div className="text-center py-8">
                  <FileSpreadsheet className="mx-auto h-12 w-12 text-muted-foreground/50" />
                  <p className="mt-4 text-muted-foreground">No uploads yet</p>
                </div>
              ) : (
                <div className="space-y-4">
                  {uploads.data.map((upload) => (
                    <div
                      key={upload.id}
                      className="flex items-center justify-between p-4 border rounded-lg"
                    >
                      <div className="flex items-center gap-4">
                        <FileSpreadsheet className="h-8 w-8 text-green-600" />
                        <div>
                          <p className="font-medium">{upload.original_filename}</p>
                          <div className="flex items-center gap-2 text-sm text-muted-foreground">
                            <span>{upload.total_rows} rows</span>
                            <span>|</span>
                            <span className="text-green-600">{upload.success_rows} success</span>
                            {upload.error_rows > 0 && (
                              <>
                                <span>|</span>
                                <span className="text-red-600">{upload.error_rows} errors</span>
                              </>
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
                          <Button
                            variant="outline"
                            size="sm"
                            onClick={() => handleRetry(upload.id)}
                          >
                            <RefreshCw className="h-4 w-4" />
                          </Button>
                        )}
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => router.visit(`/waybills/import/${upload.id}`)}
                        >
                          <Eye className="h-4 w-4" />
                        </Button>
                      </div>
                    </div>
                  ))}

                  {/* Pagination */}
                  {uploads.links.length > 3 && (
                    <div className="flex justify-center gap-1 pt-4">
                      {uploads.links.map((link, index) => (
                        <Button
                          key={index}
                          variant={link.active ? 'default' : 'outline'}
                          size="sm"
                          disabled={!link.url}
                          onClick={() => link.url && router.visit(link.url)}
                          dangerouslySetInnerHTML={{ __html: link.label }}
                        />
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
