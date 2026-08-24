import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Upload, Search, Trash2, FileSpreadsheet } from 'lucide-react';
import TelesalesLayout from '@/layouts/TelesalesLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';

interface PriceRemark {
  id: number;
  price_key: string;
  remarks: string;
  created_at: string;
}

interface PaginatedRemarks {
  data: PriceRemark[];
  current_page: number;
  last_page: number;
  total: number;
  from: number | null;
  to: number | null;
  links: Array<{ url: string | null; label: string; active: boolean }>;
}

interface Props {
  remarks: PaginatedRemarks;
  filters: { search?: string };
  stats: { total: number; uniquePrices: number };
}

export default function PriceRemarks({ remarks, filters, stats }: Props) {
  const [search, setSearch] = useState(filters.search || '');
  const importForm = useForm<{ file: File | null }>({ file: null });
  const [fileName, setFileName] = useState('');
  const { flash } = usePage().props as { flash?: { success?: string; error?: string } };

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    router.visit('/telesales/promos/price-remarks', {
      data: { search },
      preserveState: true,
      preserveScroll: true,
    });
  };

  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0] ?? null;
    importForm.setData('file', file);
    setFileName(file ? file.name : '');
  };

  const handleImport = (e: React.FormEvent) => {
    e.preventDefault();
    if (!importForm.data.file) return;

    importForm.post('/telesales/promos/price-remarks/import', {
      onSuccess: () => {
        setFileName('');
        importForm.reset();
      },
    });
  };

  const handleTruncate = () => {
    if (!confirm('This will delete all imported price remarks. Continue?')) return;
    router.post('/telesales/promos/price-remarks/truncate');
  };

  return (
    <TelesalesLayout>
      <Head title="Prices & Remarks" />

      <div className="space-y-6 p-6">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold tracking-tight">Prices & Remarks</h1>
            <p className="text-sm text-muted-foreground mt-1">
              Import and browse price-range bundle scripts for telesales agents.
            </p>
          </div>
        </div>

        {flash?.success && (
          <div className="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800">
            {flash.success}
          </div>
        )}
        {flash?.error && (
          <div className="rounded-lg border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive">
            {flash.error}
          </div>
        )}

        {/* Stats */}
        <div className="grid gap-4 md:grid-cols-3">
          <Card>
            <CardHeader className="pb-2">
              <CardDescription>Total rows</CardDescription>
              <CardTitle className="text-2xl">{stats.total.toLocaleString()}</CardTitle>
            </CardHeader>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardDescription>Unique price keys</CardDescription>
              <CardTitle className="text-2xl">{stats.uniquePrices.toLocaleString()}</CardTitle>
            </CardHeader>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardDescription>Expected columns</CardDescription>
              <CardTitle className="text-base font-medium">
                Price / range in column A, remarks in column B
              </CardTitle>
            </CardHeader>
          </Card>
        </div>

        {/* Import */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <FileSpreadsheet className="h-5 w-5" />
              Import from Excel
            </CardTitle>
            <CardDescription>
              Upload <code>.xlsx</code> or <code>.xls</code>. The first two columns are used:
              price/range and remarks.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <form onSubmit={handleImport} className="space-y-4" encType="multipart/form-data">
              <div className="flex flex-col gap-4 sm:flex-row sm:items-end">
                <div className="flex-1">
                  <label
                    htmlFor="price-remarks-file"
                    className="flex cursor-pointer items-center gap-3 rounded-lg border border-dashed border-muted-foreground/25 p-4 hover:bg-muted/50 transition-colors"
                  >
                    <Upload className="h-5 w-5 text-muted-foreground" />
                    <div className="flex-1 min-w-0">
                      <p className="text-sm font-medium">
                        {fileName || 'Click to select Excel file'}
                      </p>
                      <p className="text-xs text-muted-foreground">
                        {fileName ? 'Ready to import' : 'Supported: .xlsx, .xls, .csv'}
                      </p>
                    </div>
                    <Input
                      id="price-remarks-file"
                      type="file"
                      accept=".xlsx,.xls,.csv"
                      onChange={handleFileChange}
                      className="hidden"
                    />
                  </label>
                </div>
                <Button type="submit" disabled={!importForm.data.file || importForm.processing}>
                  {importForm.processing ? 'Importing...' : 'Import'}
                </Button>
                <Button
                  type="button"
                  variant="outline"
                  onClick={handleTruncate}
                  disabled={stats.total === 0}
                >
                  <Trash2 className="mr-1.5 h-4 w-4" />
                  Clear
                </Button>
              </div>
            </form>
          </CardContent>
        </Card>

        {/* Search */}
        <form onSubmit={handleSearch} className="flex gap-3">
          <div className="relative flex-1 max-w-sm">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
            <Input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search price key or remarks..."
              className="pl-9"
            />
          </div>
          <Button type="submit" variant="outline">
            Search
          </Button>
        </form>

        {/* Table */}
        <Card>
          <CardHeader>
            <CardTitle>Imported Price Remarks ({remarks.total})</CardTitle>
          </CardHeader>
          <CardContent>
            {remarks.data.length === 0 ? (
              <p className="text-center text-muted-foreground py-8">
                No price remarks imported yet.
              </p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b text-left text-muted-foreground">
                      <th className="pb-2 pr-4 w-32">Price Key</th>
                      <th className="pb-2 pr-4">Remarks / Promo Script</th>
                    </tr>
                  </thead>
                  <tbody>
                    {remarks.data.map((row) => (
                      <tr key={row.id} className="border-b hover:bg-muted/50">
                        <td className="py-2 pr-4 align-top">
                          <Badge variant="outline" className="font-mono">
                            {row.price_key}
                          </Badge>
                        </td>
                        <td className="py-2 pr-4 align-top whitespace-pre-wrap">{row.remarks}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>

                {/* Pagination */}
                {remarks.last_page > 1 && (
                  <div className="mt-4 flex items-center justify-between">
                    <p className="text-xs text-muted-foreground">
                      Showing {remarks.from ?? 0} to {remarks.to ?? 0} of {remarks.total}
                    </p>
                    <div className="flex gap-1">
                      {remarks.links.map((link, idx) => (
                        <Button
                          key={idx}
                          variant={link.active ? 'default' : 'outline'}
                          size="sm"
                          onClick={() => link.url && router.visit(link.url)}
                          disabled={!link.url}
                        >
                          <span dangerouslySetInnerHTML={{ __html: link.label }} />
                        </Button>
                      ))}
                    </div>
                  </div>
                )}
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </TelesalesLayout>
  );
}
