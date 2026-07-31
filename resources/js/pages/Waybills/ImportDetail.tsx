import { Head, router } from '@inertiajs/react';
import {
  FileSpreadsheet,
  ArrowLeft,
  CheckCircle,
  XCircle,
  Package,
  AlertTriangle,
  Download,
  RotateCcw,
  RefreshCw,
} from 'lucide-react';
import { useState, useEffect, useCallback } from 'react';
import axios from 'axios';
import { toast } from 'sonner';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { formatDateTime } from '@/lib/utils';

interface Waybill {
  id: number;
  waybill_number: string;
  status: string;
  receiver_name: string;
  receiver_phone: string;
  city: string;
  cod_amount: number;
  created_at: string;
}

interface Upload {
  id: number;
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
  status: string;
  errors: Array<{ row: number | string; error: string }> | null;
  uploaded_by: { name: string } | null;
  created_at: string;
  started_at: string | null;
  completed_at: string | null;
  retry_status: string | null;
  retry_count: number;
}

interface ErrorDetails {
  errors: Array<{ row: number | string; error: string }>;
  row_errors_count: number;
  batch_errors_count: number;
  retry_status: string | null;
  retry_count: number;
  can_retry: boolean;
}

interface Props {
  upload: Upload;
  waybills: {
    data: Waybill[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
  };
}

const formatImportType = (type: string | null) => {
  if (!type) return '';
  if (type === 'auto_sync') return 'Auto Sync';
  return type
    .split('_')
    .map((w: string) => w.charAt(0).toUpperCase() + w.slice(1))
    .join(' ');
};

export default function ImportDetail({ upload, waybills }: Props) {
  const [errorDetails, setErrorDetails] = useState<ErrorDetails | null>(null);
  const [retryLoading, setRetryLoading] = useState(false);
  const [retryPolling, setRetryPolling] = useState(false);

  const fetchErrorDetails = useCallback(() => {
    axios
      .get(`/waybills/import/${upload.id}/error-details`)
      .then(({ data }) => setErrorDetails(data))
      .catch(() => {});
  }, [upload.id]);

  useEffect(() => {
    if (upload.error_rows > 0) {
      fetchErrorDetails();
    }
  }, [upload.error_rows, fetchErrorDetails]);

  useEffect(() => {
    if (!retryPolling) return;
    const interval = setInterval(() => {
      fetchErrorDetails();
      if (errorDetails?.retry_status === 'completed' || errorDetails?.retry_status === 'failed') {
        setRetryPolling(false);
        router.reload({ only: ['upload'] });
      }
    }, 3000);
    return () => clearInterval(interval);
  }, [retryPolling, errorDetails?.retry_status, fetchErrorDetails]);

  const handleRetryFailedRows = () => {
    setRetryLoading(true);
    axios
      .post(`/waybills/import/${upload.id}/retry-failed-rows`)
      .then(({ data }) => {
        toast.success(data.message);
        setRetryPolling(true);
      })
      .catch((err) => {
        const msg = err.response?.data?.error || 'Failed to retry failed rows';
        toast.error(msg);
      })
      .finally(() => setRetryLoading(false));
  };

  const getRetryStatusBadge = (status: string | null) => {
    if (!status) return null;
    const map: Record<string, { label: string; variant: 'default' | 'secondary' | 'destructive' }> =
      {
        queued: { label: 'Queued', variant: 'secondary' },
        processing: { label: 'Processing', variant: 'secondary' },
        completed: { label: 'Completed', variant: 'default' },
        completed_with_errors: { label: 'Completed with Errors', variant: 'secondary' },
        completed_no_rows: { label: 'No Rows Found', variant: 'secondary' },
        failed: { label: 'Failed', variant: 'destructive' },
        failed_no_file: { label: 'File Missing', variant: 'destructive' },
      };
    const config = map[status] ?? { label: status, variant: 'secondary' as const };
    return <Badge variant={config.variant}>{config.label}</Badge>;
  };

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'DELIVERED':
        return <Badge className="bg-success/10 text-success">Delivered</Badge>;
      case 'IN_TRANSIT':
      case 'OUT_FOR_DELIVERY':
        return <Badge className="bg-info/10 text-info">In Transit</Badge>;
      case 'RETURNED':
        return <Badge className="bg-destructive/10 text-destructive">Returned</Badge>;
      case 'PENDING':
        return <Badge className="bg-warning/10 text-warning">Pending</Badge>;
      default:
        return <Badge variant="secondary">{status}</Badge>;
    }
  };

  return (
    <AppLayout>
      <Head title={`Import: ${upload.original_filename}`} />

      <div className="space-y-4">
        {/* Header */}
        <div className="flex items-center gap-4">
          <Button variant="ghost" size="icon" onClick={() => router.visit('/waybills/import')}>
            <ArrowLeft className="h-5 w-5" />
          </Button>
          <div className="flex-1">
            <h1 className="text-xl font-bold font-display tracking-tight flex items-center gap-2">
              <FileSpreadsheet className="h-6 w-6" />
              {upload.original_filename}
            </h1>
            <p className="text-muted-foreground">
              Uploaded {formatDateTime(upload.created_at)}
              {upload.uploaded_by && ` by ${upload.uploaded_by.name}`}
            </p>
            {(upload.courier || upload.import_type) && (
              <div className="flex gap-2 mt-1">
                {upload.courier && (
                  <Badge variant="secondary" className="capitalize">
                    {upload.courier.toUpperCase()}
                  </Badge>
                )}
                {upload.import_type && (
                  <Badge variant="outline">
                    Import Mode: {formatImportType(upload.import_type)}
                  </Badge>
                )}
              </div>
            )}
          </div>
        </div>

        <Card>
          <CardHeader>
            <CardTitle>Import Metadata</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-3 text-sm md:grid-cols-3">
            <div>
              <p className="text-muted-foreground">Courier Provider</p>
              <p className="font-medium">{upload.courier ? upload.courier.toUpperCase() : '-'}</p>
            </div>
            <div>
              <p className="text-muted-foreground">Import Mode</p>
              <p className="font-medium">{formatImportType(upload.import_type) || 'Auto Sync'}</p>
            </div>
            <div>
              <p className="text-muted-foreground">Status</p>
              <p className="font-medium capitalize">{upload.status.replace(/_/g, ' ')}</p>
            </div>
            <div>
              <p className="text-muted-foreground">Uploaded At</p>
              <p className="font-medium">{formatDateTime(upload.created_at)}</p>
            </div>
            <div>
              <p className="text-muted-foreground">Started At</p>
              <p className="font-medium">
                {upload.started_at ? formatDateTime(upload.started_at) : '-'}
              </p>
            </div>
            <div>
              <p className="text-muted-foreground">Completed At</p>
              <p className="font-medium">
                {upload.completed_at ? formatDateTime(upload.completed_at) : '-'}
              </p>
            </div>
          </CardContent>
        </Card>

        {/* Stats Cards */}
        <div className="grid gap-4 md:grid-cols-5">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium">Total Rows</CardTitle>
              <Package className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-xl font-bold font-display">{upload.total_rows}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium">Inserted</CardTitle>
              <CheckCircle className="h-4 w-4 text-success" />
            </CardHeader>
            <CardContent>
              <div className="text-xl font-bold font-display text-success">
                {upload.inserted_rows}
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium">Updated</CardTitle>
              <CheckCircle className="h-4 w-4 text-info" />
            </CardHeader>
            <CardContent>
              <div className="text-xl font-bold font-display text-info">{upload.updated_rows}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium">Skipped</CardTitle>
              <Package className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-xl font-bold font-display text-muted-foreground">
                {upload.skipped_rows}
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium">Failed</CardTitle>
              <XCircle className="h-4 w-4 text-destructive" />
            </CardHeader>
            <CardContent>
              <div className="text-xl font-bold font-display text-destructive">
                {upload.error_rows}
              </div>
            </CardContent>
          </Card>
        </div>

        {/* Errors */}
        {upload.error_rows > 0 && (
          <Card className="border-destructive/20">
            <CardHeader>
              <div className="flex items-center justify-between">
                <CardTitle className="flex items-center gap-2 text-destructive">
                  <AlertTriangle className="h-5 w-5" />
                  Import Errors ({upload.error_rows} rows)
                </CardTitle>
                <div className="flex items-center gap-2">
                  {errorDetails?.can_retry && (
                    <Button
                      variant="default"
                      size="sm"
                      onClick={handleRetryFailedRows}
                      disabled={retryLoading || retryPolling}
                    >
                      {retryLoading || retryPolling ? (
                        <>
                          <RefreshCw className="h-4 w-4 mr-1 animate-spin" />
                          Retrying...
                        </>
                      ) : (
                        <>
                          <RotateCcw className="h-4 w-4 mr-1" />
                          Retry Failed Rows
                        </>
                      )}
                    </Button>
                  )}
                  <Button variant="outline" size="sm" asChild>
                    <a href={`/waybills/import/${upload.id}/errors/download`}>
                      <Download className="h-4 w-4 mr-1" />
                      Download CSV
                    </a>
                  </Button>
                </div>
              </div>
              <CardDescription>
                The following rows had errors during import
                {errorDetails && (
                  <span className="ml-2">
                    — {errorDetails.row_errors_count} row errors, {errorDetails.batch_errors_count}{' '}
                    batch errors
                  </span>
                )}
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-3">
              {/* Retry status */}
              {upload.retry_status && (
                <div className="flex items-center gap-2 rounded-lg border p-2 bg-muted/50">
                  <span className="text-sm font-medium">Retry status:</span>
                  {getRetryStatusBadge(upload.retry_status)}
                  {upload.retry_count > 0 && (
                    <span className="text-xs text-muted-foreground">
                      ({upload.retry_count} {upload.retry_count === 1 ? 'retry' : 'retries'})
                    </span>
                  )}
                </div>
              )}

              {/* Error list */}
              {upload.errors && upload.errors.length > 0 ? (
                <div className="space-y-2 max-h-60 overflow-y-auto">
                  {upload.errors.map((error, index) => (
                    <div
                      key={index}
                      className="flex items-start gap-3 p-2 bg-destructive/5 rounded-lg text-sm"
                    >
                      <span className="font-mono text-destructive whitespace-nowrap">
                        Row {error.row}
                      </span>
                      <span className="text-destructive">{error.error}</span>
                    </div>
                  ))}
                </div>
              ) : (
                <p className="text-sm text-muted-foreground">
                  {upload.error_rows} rows failed. Download the error report for details.
                </p>
              )}

              {/* Retry hint */}
              {errorDetails && errorDetails.can_retry && (
                <p className="text-xs text-muted-foreground">
                  Click "Retry Failed Rows" to re-process only the failed rows from the original
                  file without re-uploading.
                </p>
              )}
              {errorDetails && !errorDetails.can_retry && errorDetails.row_errors_count === 0 && (
                <p className="text-xs text-muted-foreground">
                  Batch-level errors cannot be retried per-row. Use "Retry" to re-process the entire
                  file.
                </p>
              )}
            </CardContent>
          </Card>
        )}

        {/* Imported Waybills */}
        <Card>
          <CardHeader>
            <CardTitle>Imported Waybills</CardTitle>
            <CardDescription>Waybills created or updated from this import</CardDescription>
          </CardHeader>
          <CardContent>
            {waybills.data.length === 0 ? (
              <div className="text-center py-8">
                <Package className="mx-auto h-12 w-12 text-muted-foreground/50" />
                <p className="mt-4 text-muted-foreground">No waybills from this import</p>
              </div>
            ) : (
              <>
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="border-b">
                        <th className="text-left py-3 px-2 font-medium">Waybill #</th>
                        <th className="text-left py-3 px-2 font-medium">Receiver</th>
                        <th className="text-left py-3 px-2 font-medium">Phone</th>
                        <th className="text-left py-3 px-2 font-medium">City</th>
                        <th className="text-left py-3 px-2 font-medium">COD</th>
                        <th className="text-left py-3 px-2 font-medium">Status</th>
                      </tr>
                    </thead>
                    <tbody>
                      {waybills.data.map((waybill) => (
                        <tr key={waybill.id} className="border-b hover:bg-muted/50">
                          <td className="py-3 px-2">
                            <button
                              onClick={() => router.visit(`/waybills/${waybill.id}`)}
                              className="font-mono text-primary hover:underline"
                            >
                              {waybill.waybill_number}
                            </button>
                          </td>
                          <td className="py-3 px-2">{waybill.receiver_name}</td>
                          <td className="py-3 px-2 font-mono">{waybill.receiver_phone}</td>
                          <td className="py-3 px-2">{waybill.city}</td>
                          <td className="py-3 px-2">
                            {waybill.cod_amount > 0
                              ? `₱${waybill.cod_amount.toLocaleString()}`
                              : '-'}
                          </td>
                          <td className="py-3 px-2">{getStatusBadge(waybill.status)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>

                {/* Pagination */}
                {waybills.links.length > 3 && (
                  <div className="flex justify-center gap-1 pt-4">
                    {waybills.links.map((link, index) => (
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
              </>
            )}
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
