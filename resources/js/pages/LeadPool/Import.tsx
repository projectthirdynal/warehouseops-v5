import { useState, useRef } from 'react';
import { Head, router } from '@inertiajs/react';
import {
  Upload,
  FileText,
  CheckCircle,
  AlertCircle,
  Info,
  ArrowLeft,
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

export default function LeadPoolImport({ flash }: Props) {
  const [file, setFile] = useState<File | null>(null);
  const [isDragging, setIsDragging] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);

  const handleDrop = (e: React.DragEvent) => {
    e.preventDefault();
    setIsDragging(false);
    const dropped = e.dataTransfer.files[0];
    if (dropped && (dropped.name.endsWith('.csv') || dropped.name.endsWith('.txt'))) {
      setFile(dropped);
    }
  };

  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const selected = e.target.files?.[0];
    if (selected) setFile(selected);
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!file) return;

    setIsSubmitting(true);
    const formData = new FormData();
    formData.append('file', file);

    router.post('/lead-pool/import', formData, {
      onFinish: () => setIsSubmitting(false),
    });
  };

  const formatBytes = (bytes: number) => {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
  };

  return (
    <AppLayout>
      <Head title="Import Leads" />

      <div className="space-y-6 max-w-3xl mx-auto">
        {/* Header */}
        <div className="flex items-center gap-4">
          <Link href="/lead-pool">
            <Button variant="ghost" size="icon">
              <ArrowLeft className="h-4 w-4" />
            </Button>
          </Link>
          <div>
            <h1 className="text-2xl font-bold tracking-tight">Import Leads</h1>
            <p className="text-muted-foreground">
              Upload a CSV file to bulk-add leads to the available pool
            </p>
          </div>
        </div>

        {/* Flash messages */}
        {flash?.success && (
          <div className="flex items-start gap-3 p-4 rounded-lg bg-green-50 border border-green-200 text-green-800">
            <CheckCircle className="h-5 w-5 shrink-0 mt-0.5" />
            <p className="text-sm">{flash.success}</p>
          </div>
        )}
        {flash?.error && (
          <div className="flex items-start gap-3 p-4 rounded-lg bg-red-50 border border-red-200 text-red-800">
            <AlertCircle className="h-5 w-5 shrink-0 mt-0.5" />
            <p className="text-sm">{flash.error}</p>
          </div>
        )}

        {/* Upload form */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Upload className="h-5 w-5" />
              Upload CSV File
            </CardTitle>
          </CardHeader>
          <CardContent>
            <form onSubmit={handleSubmit} className="space-y-4">
              {/* Drop zone */}
              <div
                className={`
                  border-2 border-dashed rounded-lg p-8 text-center cursor-pointer transition-colors
                  ${isDragging ? 'border-primary bg-primary/5' : 'border-muted-foreground/25 hover:border-primary/50'}
                  ${file ? 'border-green-400 bg-green-50' : ''}
                `}
                onDragOver={(e) => { e.preventDefault(); setIsDragging(true); }}
                onDragLeave={() => setIsDragging(false)}
                onDrop={handleDrop}
                onClick={() => inputRef.current?.click()}
              >
                <input
                  ref={inputRef}
                  type="file"
                  accept=".csv,.txt"
                  onChange={handleFileChange}
                  className="hidden"
                />

                {file ? (
                  <div className="space-y-2">
                    <FileText className="mx-auto h-10 w-10 text-green-600" />
                    <p className="font-medium text-green-700">{file.name}</p>
                    <p className="text-sm text-muted-foreground">{formatBytes(file.size)}</p>
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      onClick={(e) => { e.stopPropagation(); setFile(null); }}
                    >
                      Remove
                    </Button>
                  </div>
                ) : (
                  <div className="space-y-2">
                    <Upload className="mx-auto h-10 w-10 text-muted-foreground" />
                    <p className="font-medium">Drop your CSV here or click to browse</p>
                    <p className="text-sm text-muted-foreground">Accepts .csv files up to 10 MB</p>
                  </div>
                )}
              </div>

              <Button
                type="submit"
                disabled={!file || isSubmitting}
                className="w-full"
              >
                {isSubmitting ? 'Importing...' : 'Import Leads'}
              </Button>
            </form>
          </CardContent>
        </Card>

        {/* Format guide */}
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
                    <Badge variant="outline" className="font-mono shrink-0">{field}</Badge>
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

            <div className="flex items-start gap-2 p-3 bg-blue-50 rounded-lg text-sm text-blue-800">
              <Info className="h-4 w-4 shrink-0 mt-0.5" />
              <div>
                <p className="font-medium">Deduplication</p>
                <p className="text-blue-700">
                  Leads are deduplicated by phone number. Existing leads with the same phone
                  will have their city and product info updated — they won't be duplicated.
                </p>
              </div>
            </div>
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
