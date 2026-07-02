import { Head, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Upload, FileSpreadsheet, AlertCircle, CheckCircle, XCircle } from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

export default function TelesalesImport() {
  const { flash } = usePage().props as any;
  const importErrors: string[] = flash?.importErrors ?? [];

  const { data, setData, post, processing, errors, reset } = useForm({
    file: null as File | null,
  });

  const [dragActive, setDragActive] = useState(false);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!data.file) return;

    post('/telesales/import', {
      onSuccess: () => reset(),
    });
  };

  const handleDrop = (e: React.DragEvent) => {
    e.preventDefault();
    setDragActive(false);
    const dropped = e.dataTransfer.files?.[0];
    if (dropped) setData('file', dropped);
  };

  return (
    <AppLayout>
      <Head title="Telesales Import" />
      <div className="max-w-3xl mx-auto space-y-4">
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
              Upload a CSV file with the following columns (no header row required):
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
              Existing customers (matched by phone) will be updated. Leads with status "Delivered"
              get a quality score of 85.
            </p>
          </CardContent>
        </Card>

        {/* Upload Form */}
        <form onSubmit={handleSubmit}>
          <Card>
            <CardContent className="pt-6">
              <div
                className={`border-2 border-dashed rounded-lg p-8 text-center transition-colors ${
                  dragActive ? 'border-primary bg-primary/5' : 'border-border'
                } ${errors.file ? 'border-destructive' : ''}`}
                onDragOver={(e) => {
                  e.preventDefault();
                  setDragActive(true);
                }}
                onDragLeave={() => setDragActive(false)}
                onDrop={handleDrop}
              >
                <Upload className="mx-auto h-8 w-8 text-muted-foreground mb-3" />
                <p className="text-sm font-medium">
                  {data.file ? data.file.name : 'Drag & drop a CSV file here'}
                </p>
                <p className="text-xs text-muted-foreground mt-1">or click to browse (max 10 MB)</p>
                <input
                  type="file"
                  accept=".csv,.txt"
                  className="hidden"
                  id="csv-upload"
                  onChange={(e) => {
                    const f = e.target.files?.[0];
                    if (f) setData('file', f);
                  }}
                />
                <label htmlFor="csv-upload">
                  <Button type="button" variant="outline" size="sm" className="mt-3" asChild>
                    <span>Browse Files</span>
                  </Button>
                </label>
                {errors.file && <p className="text-xs text-destructive mt-2">{errors.file}</p>}
              </div>

              <div className="flex justify-end mt-4">
                <Button type="submit" disabled={processing || !data.file}>
                  {processing ? 'Importing...' : 'Import Telesales Leads'}
                </Button>
              </div>
            </CardContent>
          </Card>
        </form>
      </div>
    </AppLayout>
  );
}
