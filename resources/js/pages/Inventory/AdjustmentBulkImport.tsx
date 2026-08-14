import { useState, useRef } from 'react';
import { Head, Link } from '@inertiajs/react';
import {
  Upload,
  FileSpreadsheet,
  Download,
  CheckCircle2,
  XCircle,
  AlertTriangle,
  ArrowRight,
  ArrowLeft,
  RotateCcw,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Progress } from '@/components/ui/progress';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';

interface ValidRow {
  row_number: number;
  item_type: string;
  sku: string;
  item_name: string;
  variant_sku: string | null;
  variant_id: number | null;
  warehouse_code: string;
  warehouse_name: string;
  quantity_before: number | null;
  quantity_after: number;
  variance: number | null;
  reason_code: string;
  reason_notes: string | null;
  is_valid: boolean;
  errors: string[];
}

interface ErrorRow {
  row_number: number;
  sku: string;
  errors: string[];
}

interface PreviewSummary {
  total_rows: number;
  valid_count: number;
  error_count: number;
  warning_count: number;
}

interface PreviewData {
  headers: string[];
  valid_rows: ValidRow[];
  error_rows: ErrorRow[];
  warnings: string[];
  summary: PreviewSummary;
}

interface ImportResult {
  created: number;
  errors: string[];
}

interface Props {
  reason_codes: string[];
  item_types: string[];
  required_headers: string[];
  optional_headers: string[];
}

type Step = 'upload' | 'preview' | 'importing' | 'results';

export default function AdjustmentBulkImport({
  reason_codes,
  required_headers,
  optional_headers,
}: Props) {
  const [step, setStep] = useState<Step>('upload');
  const [previewData, setPreviewData] = useState<PreviewData | null>(null);
  const [previewErrors, setPreviewErrors] = useState<string[]>([]);
  const [uploading, setUploading] = useState(false);
  const [importResult, setImportResult] = useState<ImportResult | null>(null);
  const [importing, setImporting] = useState(false);
  const fileInputRef = useRef<HTMLInputElement>(null);

  function handleFileUpload(event: React.ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];
    if (!file) return;

    setUploading(true);
    setPreviewErrors([]);

    const formData = new FormData();
    formData.append('file', file);

    fetch('/inventory/adjustment-bulk-import/preview', {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN':
          document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
      },
      body: formData,
    })
      .then(async (res) => {
        const data = await res.json();
        if (!res.ok) {
          setPreviewErrors(data.errors || ['Upload failed.']);
          setStep('upload');
          return;
        }
        setPreviewData(data);
        setStep('preview');
      })
      .catch(() => {
        setPreviewErrors(['Network error during upload.']);
        setStep('upload');
      })
      .finally(() => {
        setUploading(false);
        if (fileInputRef.current) fileInputRef.current.value = '';
      });
  }

  function handleConfirmImport() {
    if (!previewData || previewData.valid_rows.length === 0) return;

    setImporting(true);
    setStep('importing');

    fetch('/inventory/adjustment-bulk-import/confirm', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN':
          document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
      },
      body: JSON.stringify({ rows: previewData.valid_rows }),
    })
      .then(async (res) => {
        const data: ImportResult = await res.json();
        setImportResult(data);
        setStep('results');
      })
      .catch(() => {
        setImportResult({ created: 0, errors: ['Network error during import.'] });
        setStep('results');
      })
      .finally(() => setImporting(false));
  }

  function handleReset() {
    setStep('upload');
    setPreviewData(null);
    setPreviewErrors([]);
    setImportResult(null);
  }

  function downloadTemplate() {
    window.location.href = '/inventory/adjustment-bulk-import/template';
  }

  const progressPercent = previewData
    ? Math.round((previewData.summary.valid_count / previewData.summary.total_rows) * 100)
    : 0;

  return (
    <AppLayout>
      <Head title="Adjustment Bulk Import" />
      <div className="space-y-5 p-6">
        {/* Header */}
        <div className="mb-4">
          <div className="mb-1 flex items-center gap-1 text-xs text-muted-foreground">
            <Link href="/inventory" className="hover:text-foreground">
              Inventory
            </Link>
            <span>/</span>
            <Link href="/inventory/adjustments" className="hover:text-foreground">
              Adjustments
            </Link>
            <span>/</span>
            <span>Bulk Import</span>
          </div>
          <h1 className="flex items-center gap-2 text-2xl font-bold tracking-tight">
            <Upload className="h-5 w-5 text-info" />
            Adjustment Bulk Import
          </h1>
          <p className="mt-0.5 text-sm text-muted-foreground">
            Upload a CSV file to create multiple stock adjustments at once. All adjustments are
            created as PENDING and require approval.
          </p>
        </div>

        {/* Step indicator */}
        <div className="flex items-center gap-2 text-sm">
          <StepBadge
            number={1}
            label="Upload"
            active={step === 'upload'}
            done={step !== 'upload'}
          />
          <ArrowRight className="h-4 w-4 text-muted-foreground" />
          <StepBadge
            number={2}
            label="Preview & Validate"
            active={step === 'preview' || step === 'importing'}
            done={step === 'results'}
          />
          <ArrowRight className="h-4 w-4 text-muted-foreground" />
          <StepBadge number={3} label="Results" active={step === 'results'} done={false} />
        </div>

        {/* Step 1: Upload */}
        {step === 'upload' && (
          <Card>
            <CardHeader>
              <CardTitle className="text-sm">Upload CSV File</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              {/* Template download */}
              <div className="flex items-center justify-between rounded-lg border p-4">
                <div>
                  <p className="text-sm font-medium">CSV Template</p>
                  <p className="text-xs text-muted-foreground">
                    Download the template with correct headers and example rows.
                  </p>
                </div>
                <Button onClick={downloadTemplate} variant="outline" size="sm">
                  <Download className="mr-1.5 h-3.5 w-3.5" />
                  Download Template
                </Button>
              </div>

              {/* Required headers info */}
              <div className="rounded-lg border bg-muted/30 p-4">
                <p className="mb-2 text-xs font-medium uppercase text-muted-foreground">
                  Required Columns
                </p>
                <div className="flex flex-wrap gap-2">
                  {required_headers.map((h) => (
                    <Badge key={h} variant="secondary" className="font-mono text-xs">
                      {h}
                    </Badge>
                  ))}
                </div>
                <p className="mb-2 mt-3 text-xs font-medium uppercase text-muted-foreground">
                  Optional Columns
                </p>
                <div className="flex flex-wrap gap-2">
                  {optional_headers.map((h) => (
                    <Badge key={h} variant="outline" className="font-mono text-xs">
                      {h}
                    </Badge>
                  ))}
                </div>
                <p className="mb-1 mt-3 text-xs font-medium uppercase text-muted-foreground">
                  Valid Reason Codes
                </p>
                <div className="flex flex-wrap gap-1.5">
                  {reason_codes.map((c) => (
                    <Badge key={c} variant="outline" className="text-xs">
                      {c}
                    </Badge>
                  ))}
                </div>
              </div>

              {/* Upload errors */}
              {previewErrors.length > 0 && (
                <div className="rounded-lg border border-destructive/30 bg-destructive/10 p-4">
                  <div className="mb-2 flex items-center gap-2 text-sm font-medium text-destructive">
                    <XCircle className="h-4 w-4" />
                    Upload Errors
                  </div>
                  <ul className="space-y-1 text-xs text-destructive">
                    {previewErrors.map((err, i) => (
                      <li key={i}>• {err}</li>
                    ))}
                  </ul>
                </div>
              )}

              {/* File upload */}
              <div
                className="flex flex-col items-center justify-center rounded-lg border-2 border-dashed border-muted-foreground/30 p-12"
                onDragOver={(e) => e.preventDefault()}
                onDrop={(e) => {
                  e.preventDefault();
                  const file = e.dataTransfer.files?.[0];
                  if (file) {
                    const input = fileInputRef.current;
                    if (input) {
                      const dt = new DataTransfer();
                      dt.items.add(file);
                      input.files = dt.files;
                      handleFileUpload({ target: { files: [file] } } as any);
                    }
                  }
                }}
              >
                <FileSpreadsheet className="mb-3 h-10 w-10 text-muted-foreground" />
                <p className="mb-2 text-sm font-medium">
                  Drag & drop CSV file here, or click to browse
                </p>
                <p className="mb-4 text-xs text-muted-foreground">
                  Max 5MB. Supported format: .csv
                </p>
                <input
                  ref={fileInputRef}
                  type="file"
                  accept=".csv,.txt"
                  onChange={handleFileUpload}
                  className="hidden"
                />
                <Button onClick={() => fileInputRef.current?.click()} disabled={uploading}>
                  <Upload className="mr-1.5 h-4 w-4" />
                  {uploading ? 'Uploading...' : 'Select File'}
                </Button>
              </div>
            </CardContent>
          </Card>
        )}

        {/* Step 2: Preview */}
        {(step === 'preview' || step === 'importing') && previewData && (
          <div className="space-y-4">
            {/* Summary */}
            <div className="grid grid-cols-4 gap-4">
              <SummaryCard
                label="Total Rows"
                value={previewData.summary.total_rows}
                icon={<FileSpreadsheet className="h-4 w-4" />}
                accent="info"
              />
              <SummaryCard
                label="Valid"
                value={previewData.summary.valid_count}
                icon={<CheckCircle2 className="h-4 w-4" />}
                accent="success"
              />
              <SummaryCard
                label="Errors"
                value={previewData.summary.error_count}
                icon={<XCircle className="h-4 w-4" />}
                accent={previewData.summary.error_count > 0 ? 'destructive' : 'muted'}
              />
              <SummaryCard
                label="Warnings"
                value={previewData.summary.warning_count}
                icon={<AlertTriangle className="h-4 w-4" />}
                accent={previewData.summary.warning_count > 0 ? 'warning' : 'muted'}
              />
            </div>

            {/* Progress bar */}
            <Card>
              <CardContent className="p-4">
                <div className="mb-2 flex items-center justify-between text-sm">
                  <span className="font-medium">Validation Progress</span>
                  <span className="text-muted-foreground">
                    {previewData.summary.valid_count} of {previewData.summary.total_rows} rows valid
                    ({progressPercent}%)
                  </span>
                </div>
                <Progress value={progressPercent} />
              </CardContent>
            </Card>

            {/* Warnings */}
            {previewData.warnings.length > 0 && (
              <div className="rounded-lg border border-warning/30 bg-warning/10 p-4">
                <div className="mb-2 flex items-center gap-2 text-sm font-medium text-warning">
                  <AlertTriangle className="h-4 w-4" />
                  Warnings
                </div>
                <ul className="space-y-1 text-xs text-warning">
                  {previewData.warnings.map((w, i) => (
                    <li key={i}>• {w}</li>
                  ))}
                </ul>
              </div>
            )}

            {/* Error rows */}
            {previewData.error_rows.length > 0 && (
              <Card>
                <CardHeader>
                  <CardTitle className="flex items-center gap-2 text-sm text-destructive">
                    <XCircle className="h-4 w-4" />
                    Error Rows ({previewData.error_rows.length})
                  </CardTitle>
                </CardHeader>
                <CardContent className="p-0">
                  <div className="max-h-[300px] overflow-auto">
                    <Table>
                      <TableHeader>
                        <TableRow>
                          <TableHead className="w-16">Row</TableHead>
                          <TableHead>SKU</TableHead>
                          <TableHead>Errors</TableHead>
                        </TableRow>
                      </TableHeader>
                      <TableBody>
                        {previewData.error_rows.map((row) => (
                          <TableRow key={row.row_number}>
                            <TableCell className="font-mono text-xs">{row.row_number}</TableCell>
                            <TableCell className="font-mono text-xs">{row.sku || '-'}</TableCell>
                            <TableCell>
                              <ul className="space-y-0.5 text-xs text-destructive">
                                {row.errors.map((err, i) => (
                                  <li key={i}>• {err}</li>
                                ))}
                              </ul>
                            </TableCell>
                          </TableRow>
                        ))}
                      </TableBody>
                    </Table>
                  </div>
                </CardContent>
              </Card>
            )}

            {/* Valid rows preview */}
            {previewData.valid_rows.length > 0 && (
              <Card>
                <CardHeader>
                  <CardTitle className="flex items-center gap-2 text-sm text-success">
                    <CheckCircle2 className="h-4 w-4" />
                    Valid Rows ({previewData.valid_rows.length})
                  </CardTitle>
                </CardHeader>
                <CardContent className="p-0">
                  <div className="max-h-[400px] overflow-auto">
                    <Table>
                      <TableHeader>
                        <TableRow>
                          <TableHead className="w-16">Row</TableHead>
                          <TableHead>Type</TableHead>
                          <TableHead>SKU</TableHead>
                          <TableHead>Item</TableHead>
                          <TableHead>Warehouse</TableHead>
                          <TableHead className="text-right">Before</TableHead>
                          <TableHead className="text-right">After</TableHead>
                          <TableHead className="text-right">Variance</TableHead>
                          <TableHead>Reason</TableHead>
                        </TableRow>
                      </TableHeader>
                      <TableBody>
                        {previewData.valid_rows.map((row) => (
                          <TableRow key={row.row_number}>
                            <TableCell className="font-mono text-xs">{row.row_number}</TableCell>
                            <TableCell>
                              <Badge variant="outline" className="text-xs">
                                {row.item_type}
                              </Badge>
                            </TableCell>
                            <TableCell className="font-mono text-xs">{row.sku}</TableCell>
                            <TableCell className="text-sm">{row.item_name}</TableCell>
                            <TableCell className="text-sm">{row.warehouse_name}</TableCell>
                            <TableCell className="text-right text-sm">
                              {row.quantity_before ?? '-'}
                            </TableCell>
                            <TableCell className="text-right font-bold">
                              {row.quantity_after}
                            </TableCell>
                            <TableCell
                              className={cn(
                                'text-right font-bold',
                                row.variance !== null && row.variance > 0 && 'text-success',
                                row.variance !== null && row.variance < 0 && 'text-destructive',
                                (row.variance === null || row.variance === 0) &&
                                  'text-muted-foreground'
                              )}
                            >
                              {row.variance !== null
                                ? (row.variance > 0 ? '+' : '') + row.variance
                                : '-'}
                            </TableCell>
                            <TableCell>
                              <Badge variant="secondary" className="text-xs">
                                {row.reason_code}
                              </Badge>
                            </TableCell>
                          </TableRow>
                        ))}
                      </TableBody>
                    </Table>
                  </div>
                </CardContent>
              </Card>
            )}

            {/* Actions */}
            <div className="flex items-center justify-between">
              <Button onClick={handleReset} variant="ghost">
                <ArrowLeft className="mr-1.5 h-4 w-4" />
                Back to Upload
              </Button>
              <Button
                onClick={handleConfirmImport}
                disabled={importing || previewData.valid_rows.length === 0}
              >
                {importing ? (
                  <>
                    <RotateCcw className="mr-1.5 h-4 w-4 animate-spin" />
                    Importing...
                  </>
                ) : (
                  <>
                    <CheckCircle2 className="mr-1.5 h-4 w-4" />
                    Import {previewData.valid_rows.length} Adjustment
                    {previewData.valid_rows.length !== 1 ? 's' : ''}
                  </>
                )}
              </Button>
            </div>
          </div>
        )}

        {/* Step 3: Results */}
        {step === 'results' && importResult && (
          <Card>
            <CardHeader>
              <CardTitle className="text-sm">Import Results</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <SummaryCard
                  label="Adjustments Created"
                  value={importResult.created}
                  icon={<CheckCircle2 className="h-4 w-4" />}
                  accent="success"
                />
                <SummaryCard
                  label="Import Errors"
                  value={importResult.errors.length}
                  icon={<XCircle className="h-4 w-4" />}
                  accent={importResult.errors.length > 0 ? 'destructive' : 'muted'}
                />
              </div>

              {importResult.errors.length > 0 && (
                <div className="rounded-lg border border-destructive/30 bg-destructive/10 p-4">
                  <div className="mb-2 flex items-center gap-2 text-sm font-medium text-destructive">
                    <XCircle className="h-4 w-4" />
                    Error Details
                  </div>
                  <ul className="space-y-1 text-xs text-destructive">
                    {importResult.errors.map((err, i) => (
                      <li key={i}>• {err}</li>
                    ))}
                  </ul>
                </div>
              )}

              <div className="rounded-lg border bg-muted/30 p-4 text-sm">
                <p className="font-medium">Next Steps</p>
                <p className="mt-1 text-xs text-muted-foreground">
                  {importResult.created > 0
                    ? `${importResult.created} adjustment${importResult.created !== 1 ? 's' : ''} created as PENDING. An approver must review and approve each adjustment before stock levels are updated.`
                    : 'No adjustments were created. Please fix the errors and try again.'}
                </p>
                <div className="mt-3 flex gap-2">
                  <Link href="/inventory/adjustments">
                    <Button variant="outline" size="sm">
                      View Adjustments
                    </Button>
                  </Link>
                  <Link href="/approvals">
                    <Button variant="outline" size="sm">
                      Go to Approvals
                    </Button>
                  </Link>
                  <Button onClick={handleReset} size="sm">
                    <Upload className="mr-1.5 h-3.5 w-3.5" />
                    Import Another File
                  </Button>
                </div>
              </div>
            </CardContent>
          </Card>
        )}
      </div>
    </AppLayout>
  );
}

function StepBadge({
  number,
  label,
  active,
  done,
}: {
  number: number;
  label: string;
  active: boolean;
  done: boolean;
}) {
  return (
    <div className="flex items-center gap-2">
      <div
        className={cn(
          'flex h-6 w-6 items-center justify-center rounded-full text-xs font-bold',
          done && 'bg-success text-white',
          active && 'bg-primary text-white',
          !done && !active && 'bg-muted text-muted-foreground'
        )}
      >
        {done ? <CheckCircle2 className="h-3.5 w-3.5" /> : number}
      </div>
      <span
        className={cn(
          'text-sm font-medium',
          active && 'text-foreground',
          !active && !done && 'text-muted-foreground'
        )}
      >
        {label}
      </span>
    </div>
  );
}

function SummaryCard({
  label,
  value,
  icon,
  accent,
}: {
  label: string;
  value: number;
  icon: React.ReactNode;
  accent: 'info' | 'success' | 'warning' | 'destructive' | 'muted';
}) {
  const accentClass = {
    info: 'bg-info/10 text-info',
    success: 'bg-success/10 text-success',
    warning: 'bg-warning/10 text-warning',
    destructive: 'bg-destructive/10 text-destructive',
    muted: 'bg-muted text-muted-foreground',
  }[accent];

  return (
    <Card>
      <CardContent className="p-4">
        <div className="flex items-center justify-between">
          <span className="text-xs font-medium uppercase text-muted-foreground">{label}</span>
          <span className={accentClass}>{icon}</span>
        </div>
        <div className="mt-2 text-xl font-bold">{value}</div>
      </CardContent>
    </Card>
  );
}
