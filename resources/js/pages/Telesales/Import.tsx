import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
  Upload,
  FileSpreadsheet,
  AlertCircle,
  CheckCircle,
  XCircle,
  Copy,
  Database,
  FileWarning,
  RefreshCw,
  Loader2,
  FileText,
  ArrowRight,
  ArrowLeft,
  ListChecks,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';

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

interface DetectedColumn {
  index: number;
  label: string;
  samples: string[];
}

interface ColumnsResult {
  columns: DetectedColumn[];
  suggested_mapping: Record<string, number>;
  has_header: boolean;
}

interface TargetField {
  key: string;
  label: string;
  required: boolean;
}

const TARGET_FIELDS: TargetField[] = [
  { key: 'name', label: 'Customer Name', required: true },
  { key: 'phone', label: 'Phone Number', required: true },
  { key: 'address', label: 'Address', required: false },
  { key: 'province', label: 'Province / Region', required: false },
  { key: 'city', label: 'City / Municipality', required: false },
  { key: 'barangay', label: 'Barangay', required: false },
  { key: 'amount', label: 'Order Amount', required: false },
  { key: 'product_name', label: 'Product Name', required: false },
  { key: 'order_status', label: 'Order Status', required: false },
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

export default function TelesalesImport() {
  const { flash } = usePage().props as any;
  const importErrors: string[] = flash?.importErrors ?? [];

  const [file, setFile] = useState<File | null>(null);
  const [dragActive, setDragActive] = useState(false);
  const [isDetecting, setIsDetecting] = useState(false);
  const [isPreviewing, setIsPreviewing] = useState(false);
  const [isImporting, setIsImporting] = useState(false);
  const [columnsResult, setColumnsResult] = useState<ColumnsResult | null>(null);
  const [mapping, setMapping] = useState<Record<string, string>>({});
  const [preview, setPreview] = useState<PreviewResult | null>(null);
  const [filter, setFilter] = useState<'all' | 'new' | 'duplicates' | 'errors'>('all');

  const buildMappingFormData = (formData: FormData) => {
    Object.entries(mapping).forEach(([field, index]) => {
      if (index !== 'none' && index !== '') {
        formData.append(`mapping[${field}]`, index);
      }
    });
  };

  const handleDetectColumns = (e: React.FormEvent) => {
    e.preventDefault();
    if (!file) return;

    setIsDetecting(true);
    const formData = new FormData();
    formData.append('file', file);

    fetch('/telesales/import/columns', {
      method: 'POST',
      body: formData,
      headers: {
        'X-CSRF-TOKEN':
          (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
        Accept: 'application/json',
      },
    })
      .then((res) => {
        if (!res.ok) throw new Error('Column detection failed');
        return res.json();
      })
      .then((data: ColumnsResult) => {
        setColumnsResult(data);
        const initialMapping: Record<string, string> = {};
        TARGET_FIELDS.forEach((f) => {
          const idx = data.suggested_mapping[f.key];
          initialMapping[f.key] = idx !== undefined ? String(idx) : 'none';
        });
        setMapping(initialMapping);
      })
      .catch(() => setColumnsResult(null))
      .finally(() => setIsDetecting(false));
  };

  const handlePreview = () => {
    if (!file) return;

    setIsPreviewing(true);
    const formData = new FormData();
    formData.append('file', file);
    buildMappingFormData(formData);

    fetch('/telesales/import/preview', {
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
    buildMappingFormData(formData);

    router.post('/telesales/import', formData, {
      onFinish: () => setIsImporting(false),
    });
  };

  const handleReset = () => {
    setFile(null);
    setColumnsResult(null);
    setMapping({});
    setPreview(null);
    setFilter('all');
  };

  const handleBackToMapping = () => {
    setPreview(null);
    setFilter('all');
  };

  const mappingIsValid = TARGET_FIELDS.filter((f) => f.required).every(
    (f) => mapping[f.key] && mapping[f.key] !== 'none'
  );

  const handleDrop = (e: React.DragEvent) => {
    e.preventDefault();
    setDragActive(false);
    const dropped = e.dataTransfer.files?.[0];
    if (dropped) {
      setFile(dropped);
      setPreview(null);
    }
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
      <Head title="Telesales Import" />
      <div className="max-w-4xl mx-auto space-y-4">
        <div>
          <h1 className="text-xl font-bold font-display tracking-tight">Telesales Import</h1>
          <p className="text-sm text-muted-foreground">
            Import old sales data for telesales re-engagement (reorders, upsells, referrals).
          </p>
        </div>

        {/* Flash Messages */}
        {flash?.success && (
          <div className="flex items-center gap-2 rounded-lg border border-success/50 bg-success/50/10 p-3 text-sm text-success">
            <CheckCircle className="h-4 w-4" />
            <span>{flash.success}</span>
          </div>
        )}

        {importErrors.length > 0 && (
          <div className="space-y-2">
            <div className="flex items-center gap-2 rounded-lg border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">
              <XCircle className="h-4 w-4" />
              <span>{importErrors.length} row(s) had errors during import.</span>
            </div>
            <Card>
              <CardContent className="py-3">
                <ul className="space-y-1 text-sm text-muted-foreground max-h-48 overflow-y-auto">
                  {importErrors.map((err, i) => (
                    <li key={i} className="flex items-start gap-2">
                      <AlertCircle className="h-3 w-3 mt-1 text-destructive shrink-0" />
                      <span>{err}</span>
                    </li>
                  ))}
                </ul>
              </CardContent>
            </Card>
          </div>
        )}

        {/* Step 1: Upload */}
        {!columnsResult && !preview && (
          <>
            {/* Instructions */}
            <Card>
              <CardHeader className="pb-2">
                <CardTitle className="text-sm font-medium flex items-center gap-2">
                  <FileSpreadsheet className="h-4 w-4" />
                  Expected CSV Format
                </CardTitle>
              </CardHeader>
              <CardContent>
                <p className="text-sm text-muted-foreground mb-3">
                  Upload a CSV or XLSX file with the following columns (no header row required):
                </p>
                <div className="overflow-x-auto">
                  <table className="w-full text-sm border-collapse">
                    <thead>
                      <tr className="border-b text-left text-muted-foreground">
                        <th className="pb-2 font-medium">#</th>
                        <th className="pb-2 font-medium">Column</th>
                        <th className="pb-2 font-medium">Example</th>
                      </tr>
                    </thead>
                    <tbody>
                      {[
                        ['1', 'ID (ignored)', '—'],
                        ['2', 'Customer Name', 'Emelisa Bello Bautista'],
                        ['3', 'Phone Number', '9772053856'],
                        ['4', 'Full Address', 'Pagsibol Village, Brgy. Catmon...'],
                        ['5', 'Province / Region', 'BULACAN'],
                        ['6', 'City / Municipality', 'BULACAN-SANTA-MARIA'],
                        ['7', 'Barangay', 'CATMON'],
                        ['8–12', '(empty columns)', '—'],
                        ['13', 'Order Amount', '199'],
                        ['14', 'Product Name', 'AVOCAFE 1 SET B1T2'],
                        ['15', 'Order Status', 'Delivered'],
                      ].map(([num, col, ex]) => (
                        <tr key={num} className="border-b border-border/50">
                          <td className="py-1.5 text-muted-foreground">{num}</td>
                          <td className="py-1.5">{col}</td>
                          <td className="py-1.5 text-muted-foreground">{ex}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
                <p className="text-xs text-muted-foreground mt-3">
                  Existing customers (matched by phone) will be updated. Leads with status
                  "Delivered" get a quality score of 85.
                </p>
              </CardContent>
            </Card>

            {/* Upload Form */}
            <form onSubmit={handleDetectColumns}>
              <Card>
                <CardContent className="pt-6">
                  <div
                    className={`border-2 border-dashed rounded-lg p-8 text-center transition-colors ${
                      dragActive ? 'border-primary bg-primary/5' : 'border-border'
                    } ${file ? 'border-success/40 bg-success/5' : ''}`}
                    onDragOver={(e) => {
                      e.preventDefault();
                      setDragActive(true);
                    }}
                    onDragLeave={() => setDragActive(false)}
                    onDrop={handleDrop}
                  >
                    {file ? (
                      <div className="space-y-2">
                        <FileText className="mx-auto h-8 w-8 text-success" />
                        <p className="text-sm font-medium text-success">{file.name}</p>
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
                      <>
                        <Upload className="mx-auto h-8 w-8 text-muted-foreground mb-3" />
                        <p className="text-sm font-medium">Drag & drop a CSV or XLSX file here</p>
                        <p className="text-xs text-muted-foreground mt-1">
                          or click to browse (max 10 MB)
                        </p>
                      </>
                    )}
                    <input
                      type="file"
                      accept=".csv,.txt,.xlsx,.xls"
                      className="hidden"
                      id="csv-upload"
                      onChange={(e) => {
                        const f = e.target.files?.[0];
                        if (f) {
                          setFile(f);
                          setPreview(null);
                        }
                      }}
                    />
                    {!file && (
                      <label htmlFor="csv-upload">
                        <Button type="button" variant="outline" size="sm" className="mt-3" asChild>
                          <span>Browse Files</span>
                        </Button>
                      </label>
                    )}
                  </div>

                  <div className="flex justify-end mt-4">
                    <Button type="submit" disabled={isDetecting || !file}>
                      {isDetecting ? (
                        <>
                          <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                          Reading columns...
                        </>
                      ) : (
                        <>
                          <ListChecks className="h-4 w-4 mr-2" />
                          Detect Columns
                        </>
                      )}
                    </Button>
                  </div>
                </CardContent>
              </Card>
            </form>
          </>
        )}

        {/* Step 2: Field Mapping */}
        {columnsResult && !preview && (
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium flex items-center gap-2">
                <ListChecks className="h-4 w-4" />
                Map Columns to Fields
              </CardTitle>
              <p className="text-xs text-muted-foreground">
                {columnsResult.has_header
                  ? 'Header row detected — mapping was auto-suggested from column names.'
                  : 'No header row detected — verify the mapping below before continuing.'}
              </p>
            </CardHeader>
            <CardContent className="space-y-3">
              {TARGET_FIELDS.map((field) => (
                <div key={field.key} className="flex items-center gap-3">
                  <div className="w-40 shrink-0 text-sm font-medium">
                    {field.label}
                    {field.required && <span className="text-destructive ml-1">*</span>}
                  </div>
                  <Select
                    value={mapping[field.key] ?? 'none'}
                    onValueChange={(value) =>
                      setMapping((prev) => ({ ...prev, [field.key]: value }))
                    }
                  >
                    <SelectTrigger className="flex-1">
                      <SelectValue placeholder="Not mapped" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="none">— Not mapped —</SelectItem>
                      {columnsResult.columns.map((col) => (
                        <SelectItem key={col.index} value={String(col.index)}>
                          {col.label}
                          {col.samples[0] ? ` (e.g. "${col.samples[0]}")` : ''}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              ))}

              {!mappingIsValid && (
                <div className="flex items-center gap-2 rounded-md border border-warning/40 bg-warning/10 p-2 text-xs text-warning">
                  <AlertCircle className="h-3.5 w-3.5" />
                  Customer Name and Phone Number must be mapped before continuing.
                </div>
              )}

              <div className="flex justify-between pt-2">
                <Button type="button" variant="outline" size="sm" onClick={handleReset}>
                  <ArrowLeft className="h-4 w-4 mr-1" />
                  Start Over
                </Button>
                <Button
                  type="button"
                  onClick={handlePreview}
                  disabled={isPreviewing || !mappingIsValid}
                >
                  {isPreviewing ? (
                    <>
                      <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                      Validating...
                    </>
                  ) : (
                    <>
                      Validate & Preview
                      <ArrowRight className="h-4 w-4 ml-2" />
                    </>
                  )}
                </Button>
              </div>
            </CardContent>
          </Card>
        )}

        {/* Step 3: Preview Results */}
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
                <Button variant="outline" size="sm" onClick={handleBackToMapping}>
                  <ArrowLeft className="h-4 w-4 mr-1" />
                  Edit Mapping
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
      </div>
    </AppLayout>
  );
}
