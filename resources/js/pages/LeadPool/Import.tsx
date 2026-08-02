import { useState, useRef } from 'react';
import { Head, router } from '@inertiajs/react';
import {
  Upload,
  FileText,
  CheckCircle,
  AlertCircle,
  Info,
  ArrowLeft,
  Copy,
  Database,
  FileWarning,
  RefreshCw,
  Loader2,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Link } from '@inertiajs/react';

interface Flash {
  success?: string;
  error?: string;
}

interface Props {
  flash?: Flash;
}

interface PreviewRow {
  row: number;
  name: string;
  phone: string;
  city: string;
  status: 'new' | 'duplicate_db' | 'duplicate_file' | 'error';
  error: string | null;
}

interface PreviewSummary {
  total: number;
  new: number;
  duplicate_db: number;
  duplicate_file: number;
  errors: number;
}

interface PreviewResult {
  summary: PreviewSummary;
  rows: PreviewRow[];
}

const REQUIRED_COLUMNS = ['name', 'phone'];
const OPTIONAL_COLUMNS = [
  { field: 'city', desc: 'City / Municipality' },
  { field: 'state', desc: 'Province' },
  { field: 'barangay', desc: 'Barangay' },
  { field: 'product_interest', desc: 'Product of interest' },
  { field: 'product_brand', desc: 'Brand' },
  { field: 'amount', desc: 'Expected order value (numeric)' },
  { field: 'source', desc: 'Lead source label (defaults to CSV_IMPORT)' },
  { field: 'notes', desc: 'Internal notes' },
];

const STATUS_CONFIG = {
  new: { label: 'New', variant: 'default' as const, icon: CheckCircle, color: 'text-success' },
  duplicate_db: {
    label: 'Dup (DB)',
    variant: 'secondary' as const,
    icon: Database,
    color: 'text-warning',
  },
  duplicate_file: {
    label: 'Dup (File)',
    variant: 'secondary' as const,
    icon: Copy,
    color: 'text-warning',
  },
  error: {
    label: 'Error',
    variant: 'destructive' as const,
    icon: FileWarning,
    color: 'text-destructive',
  },
};

export default function LeadPoolImport({ flash }: Props) {
  const [file, setFile] = useState<File | null>(null);
  const [isDragging, setIsDragging] = useState(false);
  const [isPreviewing, setIsPreviewing] = useState(false);
  const [isImporting, setIsImporting] = useState(false);
  const [preview, setPreview] = useState<PreviewResult | null>(null);
  const [filter, setFilter] = useState<'all' | 'new' | 'duplicates' | 'errors'>('all');
  const inputRef = useRef<HTMLInputElement>(null);

  const handleDrop = (e: React.DragEvent) => {
    e.preventDefault();
    setIsDragging(false);
    const dropped = e.dataTransfer.files[0];
    if (
      dropped &&
      (dropped.name.endsWith('.csv') ||
        dropped.name.endsWith('.txt') ||
        dropped.name.endsWith('.xlsx') ||
        dropped.name.endsWith('.xls'))
    ) {
      setFile(dropped);
      setPreview(null);
    }
  };

  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const selected = e.target.files?.[0];
    if (selected) {
      setFile(selected);
      setPreview(null);
    }
  };

  const handlePreview = (e: React.FormEvent) => {
    e.preventDefault();
    if (!file) return;

    setIsPreviewing(true);
    const formData = new FormData();
    formData.append('file', file);

    fetch('/lead-pool/import/preview', {
      method: 'POST',
      body: formData,
      headers: {
        'X-CSRF-TOKEN':
          (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
        Accept: 'application/json',
      },
    })
      .then((res) => {
        if (!res.ok) throw new Error('Preview failed');
        return res.json();
      })
      .then((data: PreviewResult) => setPreview(data))
      .catch(() => setPreview(null))
      .finally(() => setIsPreviewing(false));
  };

  const handleConfirmImport = () => {
    if (!file) return;

    setIsImporting(true);
    const formData = new FormData();
    formData.append('file', file);

    router.post('/lead-pool/import', formData, {
      onFinish: () => setIsImporting(false),
    });
  };

  const handleReset = () => {
    setFile(null);
    setPreview(null);
    setFilter('all');
  };

  const formatBytes = (bytes: number) => {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
  };

  const filteredRows = preview
    ? preview.rows.filter((r) => {
        if (filter === 'all') return true;
        if (filter === 'new') return r.status === 'new';
        if (filter === 'duplicates')
          return r.status === 'duplicate_db' || r.status === 'duplicate_file';
        if (filter === 'errors') return r.status === 'error';
        return true;
      })
    : [];

  return (
    <AppLayout>
      <Head title="Import Leads" />

      <div className="space-y-4 max-w-4xl mx-auto">
        {/* Header */}
        <div className="flex items-center gap-4">
          <Link href="/lead-pool">
            <Button variant="ghost" size="icon">
              <ArrowLeft className="h-4 w-4" />
            </Button>
          </Link>
          <div>
            <h1 className="text-xl font-bold font-display tracking-tight">Import Leads</h1>
            <p className="text-muted-foreground">
              Upload a CSV or XLSX file to bulk-add leads to the available pool
            </p>
          </div>
        </div>

        {/* Flash messages */}
        {flash?.success && (
          <div className="flex items-start gap-3 p-4 rounded-lg bg-success/5 border border-success/20 text-success">
            <CheckCircle className="h-5 w-5 shrink-0 mt-0.5" />
            <p className="text-sm">{flash.success}</p>
          </div>
        )}
        {flash?.error && (
          <div className="flex items-start gap-3 p-4 rounded-lg bg-destructive/5 border border-destructive/20 text-destructive">
            <AlertCircle className="h-5 w-5 shrink-0 mt-0.5" />
            <p className="text-sm">{flash.error}</p>
          </div>
        )}

        {/* Step 1: Upload & Preview */}
        {!preview && (
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Upload className="h-5 w-5" />
                Upload File for Validation
              </CardTitle>
            </CardHeader>
            <CardContent>
              <form onSubmit={handlePreview} className="space-y-4">
                {/* Drop zone */}
                <div
                  className={`
                    border-2 border-dashed rounded-lg p-8 text-center cursor-pointer transition-colors
                    ${isDragging ? 'border-primary bg-primary/5' : 'border-muted-foreground/25 hover:border-primary/50'}
                    ${file ? 'border-success/40 bg-success/5' : ''}
                  `}
                  onDragOver={(e) => {
                    e.preventDefault();
                    setIsDragging(true);
                  }}
                  onDragLeave={() => setIsDragging(false)}
                  onDrop={handleDrop}
                  onClick={() => inputRef.current?.click()}
                >
                  <input
                    ref={inputRef}
                    type="file"
                    accept=".csv,.txt,.xlsx,.xls"
                    onChange={handleFileChange}
                    className="hidden"
                  />

                  {file ? (
                    <div className="space-y-2">
                      <FileText className="mx-auto h-10 w-10 text-success" />
                      <p className="font-medium text-success">{file.name}</p>
                      <p className="text-sm text-muted-foreground">{formatBytes(file.size)}</p>
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={(e) => {
                          e.stopPropagation();
                          handleReset();
                        }}
                      >
                        Remove
                      </Button>
                    </div>
                  ) : (
                    <div className="space-y-2">
                      <Upload className="mx-auto h-10 w-10 text-muted-foreground" />
                      <p className="font-medium">Drop your file here or click to browse</p>
                      <p className="text-sm text-muted-foreground">
                        Accepts .csv, .txt, .xlsx up to 10 MB
                      </p>
                    </div>
                  )}
                </div>

                <Button type="submit" disabled={!file || isPreviewing} className="w-full">
                  {isPreviewing ? (
                    <>
                      <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                      Validating...
                    </>
                  ) : (
                    <>
                      <FileText className="h-4 w-4 mr-2" />
                      Validate & Preview
                    </>
                  )}
                </Button>
              </form>
            </CardContent>
          </Card>
        )}

        {/* Step 2: Preview Results */}
        {preview && (
          <div className="space-y-4">
            {/* Summary cards */}
            <div className="grid grid-cols-2 md:grid-cols-5 gap-3">
              <Card>
                <CardContent className="pt-4 pb-4 px-4">
                  <p className="text-xs text-muted-foreground">Total Rows</p>
                  <p className="text-2xl font-bold">{preview.summary.total}</p>
                </CardContent>
              </Card>
              <Card>
                <CardContent className="pt-4 pb-4 px-4">
                  <p className="text-xs text-success">New</p>
                  <p className="text-2xl font-bold text-success">{preview.summary.new}</p>
                </CardContent>
              </Card>
              <Card>
                <CardContent className="pt-4 pb-4 px-4">
                  <p className="text-xs text-warning">Dup (DB)</p>
                  <p className="text-2xl font-bold text-warning">{preview.summary.duplicate_db}</p>
                </CardContent>
              </Card>
              <Card>
                <CardContent className="pt-4 pb-4 px-4">
                  <p className="text-xs text-warning">Dup (File)</p>
                  <p className="text-2xl font-bold text-warning">
                    {preview.summary.duplicate_file}
                  </p>
                </CardContent>
              </Card>
              <Card>
                <CardContent className="pt-4 pb-4 px-4">
                  <p className="text-xs text-destructive">Errors</p>
                  <p className="text-2xl font-bold text-destructive">{preview.summary.errors}</p>
                </CardContent>
              </Card>
            </div>

            {/* Action bar */}
            <div className="flex items-center justify-between gap-4">
              <div className="flex gap-2">
                <Button
                  variant={filter === 'all' ? 'default' : 'outline'}
                  size="sm"
                  onClick={() => setFilter('all')}
                >
                  All ({preview.summary.total})
                </Button>
                <Button
                  variant={filter === 'new' ? 'default' : 'outline'}
                  size="sm"
                  onClick={() => setFilter('new')}
                >
                  New ({preview.summary.new})
                </Button>
                <Button
                  variant={filter === 'duplicates' ? 'default' : 'outline'}
                  size="sm"
                  onClick={() => setFilter('duplicates')}
                >
                  Duplicates ({preview.summary.duplicate_db + preview.summary.duplicate_file})
                </Button>
                <Button
                  variant={filter === 'errors' ? 'default' : 'outline'}
                  size="sm"
                  onClick={() => setFilter('errors')}
                >
                  Errors ({preview.summary.errors})
                </Button>
              </div>
              <div className="flex gap-2">
                <Button variant="outline" size="sm" onClick={handleReset}>
                  <RefreshCw className="h-4 w-4 mr-1" />
                  Start Over
                </Button>
                <Button
                  onClick={handleConfirmImport}
                  disabled={isImporting || preview.summary.new === 0}
                >
                  {isImporting ? (
                    <>
                      <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                      Importing...
                    </>
                  ) : (
                    <>
                      <CheckCircle className="h-4 w-4 mr-2" />
                      Confirm Import ({preview.summary.new} new)
                    </>
                  )}
                </Button>
              </div>
            </div>

            {/* Preview table */}
            <Card>
              <CardContent className="p-0">
                <div className="max-h-[500px] overflow-y-auto">
                  <table className="w-full text-sm">
                    <thead className="sticky top-0 bg-muted/50 backdrop-blur">
                      <tr className="text-left text-muted-foreground border-b">
                        <th className="px-3 py-2 font-medium">Row</th>
                        <th className="px-3 py-2 font-medium">Status</th>
                        <th className="px-3 py-2 font-medium">Name</th>
                        <th className="px-3 py-2 font-medium">Phone</th>
                        <th className="px-3 py-2 font-medium">City</th>
                        <th className="px-3 py-2 font-medium">Issue</th>
                      </tr>
                    </thead>
                    <tbody>
                      {filteredRows.map((row) => {
                        const cfg = STATUS_CONFIG[row.status];
                        const Icon = cfg.icon;
                        return (
                          <tr key={row.row} className="border-b border-border/40 hover:bg-muted/30">
                            <td className="px-3 py-2 text-muted-foreground">{row.row}</td>
                            <td className="px-3 py-2">
                              <Badge variant={cfg.variant} className="gap-1">
                                <Icon className={`h-3 w-3 ${cfg.color}`} />
                                {cfg.label}
                              </Badge>
                            </td>
                            <td className="px-3 py-2">{row.name || '—'}</td>
                            <td className="px-3 py-2 font-mono text-xs">{row.phone || '—'}</td>
                            <td className="px-3 py-2">{row.city || '—'}</td>
                            <td className="px-3 py-2 text-muted-foreground text-xs">
                              {row.error || '—'}
                            </td>
                          </tr>
                        );
                      })}
                      {filteredRows.length === 0 && (
                        <tr>
                          <td colSpan={6} className="px-3 py-8 text-center text-muted-foreground">
                            No rows match this filter.
                          </td>
                        </tr>
                      )}
                    </tbody>
                  </table>
                </div>
              </CardContent>
            </Card>
          </div>
        )}

        {/* Format guide (only when not in preview mode) */}
        {!preview && (
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-base">
                <Info className="h-4 w-4" />
                CSV Format Guide
              </CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div>
                <p className="text-sm font-medium mb-2">Required columns</p>
                <div className="flex gap-2">
                  {REQUIRED_COLUMNS.map((col) => (
                    <Badge key={col} variant="destructive" className="font-mono">
                      {col}
                    </Badge>
                  ))}
                </div>
              </div>

              <div>
                <p className="text-sm font-medium mb-2">Optional columns</p>
                <div className="grid grid-cols-2 gap-2">
                  {OPTIONAL_COLUMNS.map(({ field, desc }) => (
                    <div key={field} className="flex items-start gap-2 text-sm">
                      <Badge variant="outline" className="font-mono shrink-0">
                        {field}
                      </Badge>
                      <span className="text-muted-foreground">{desc}</span>
                    </div>
                  ))}
                </div>
              </div>

              <div className="p-3 bg-muted rounded-lg">
                <p className="text-sm font-medium mb-1">Example CSV</p>
                <pre className="text-xs text-muted-foreground overflow-x-auto whitespace-pre">
                  {`name,phone,city,state,barangay,product_interest,amount
Juan Dela Cruz,09171234567,Davao City,Davao del Sur,Poblacion,Sneakers,1200
Maria Santos,09281234567,Cebu City,Cebu,Lahug,Running Shoes,1500`}
                </pre>
              </div>

              <div className="flex items-start gap-2 p-3 bg-info/5 rounded-lg text-sm text-info">
                <Info className="h-4 w-4 shrink-0 mt-0.5" />
                <div>
                  <p className="font-medium">Validation & Deduplication</p>
                  <p className="text-info">
                    Click <strong>Validate & Preview</strong> to check for duplicates before
                    importing. Duplicates are detected by phone number — both within the file and
                    against existing leads in the database. Only new rows will be imported when you
                    confirm.
                  </p>
                </div>
              </div>
            </CardContent>
          </Card>
        )}
      </div>
    </AppLayout>
  );
}
