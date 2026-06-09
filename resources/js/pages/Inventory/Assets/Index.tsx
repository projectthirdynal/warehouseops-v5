import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import {
  Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table';
import { ArrowLeft, Plus, Search } from 'lucide-react';
import { formatCurrency, formatDate } from '@/lib/utils';
import type { PaginatedResponse } from '@/types';

interface CapexAsset {
  id: number;
  asset_code: string;
  name: string;
  category: string;
  depreciation_years: number;
  purchase_date: string;
  acquisition_cost: number;
  current_book_value: number;
  status: 'ACTIVE' | 'DISPOSED' | 'UNDER_REPAIR';
  warehouse?: { id: number; name: string; code: string };
  assigned_user?: { id: number; name: string };
  uom?: { name: string; abbreviation: string };
}

interface Props {
  assets: PaginatedResponse<CapexAsset>;
  stats: {
    total: number;
    active: number;
    disposed: number;
    total_cost: number;
    total_book_value: number;
    due_depreciation: number;
  };
  filters: { search?: string; status?: string; category?: string; dep_years?: string };
  categories: Record<string, string>;
}

const STATUS_COLORS: Record<string, string> = {
  ACTIVE:       'bg-green-100 text-green-700',
  DISPOSED:     'bg-gray-200 text-gray-600',
  UNDER_REPAIR: 'bg-amber-100 text-amber-700',
};

export default function AssetsIndex({ assets, stats, filters, categories }: Props) {
  const [search, setSearch] = useState(filters.search ?? '');

  function applyFilters(overrides: Record<string, string>) {
    router.get('/inventory/assets', { ...filters, ...overrides }, { preserveState: true, replace: true });
  }

  return (
    <AppLayout>
      <Head title="CAPEX Assets" />
      <div className="space-y-6 p-6">
        {/* Header */}
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <div className="mb-1">
              <Link href="/inventory/supplies" className="flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground">
                <ArrowLeft className="h-3 w-3" /> Materials
              </Link>
            </div>
            <h1 className="text-2xl font-bold">CAPEX Assets — Section 3</h1>
            <p className="mt-0.5 text-sm text-muted-foreground">Fixed assets with 1, 2, or 3-year straight-line depreciation.</p>
          </div>
          <Link href="/inventory/assets/create">
            <Button><Plus className="mr-2 h-4 w-4" />New Asset</Button>
          </Link>
        </div>

        {/* KPIs */}
        <div className="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-6">
          <KpiCard label="Total Assets" value={stats.total} />
          <KpiCard label="Active" value={stats.active} />
          <KpiCard label="Disposed" value={stats.disposed} />
          <KpiCard label="Total Cost" value={formatCurrency(stats.total_cost)} />
          <KpiCard label="Book Value" value={formatCurrency(stats.total_book_value)} />
          <KpiCard label="Dep. Due" value={stats.due_depreciation} tone={stats.due_depreciation > 0 ? 'warn' : undefined} />
        </div>

        {/* Filters */}
        <Card>
          <CardContent className="flex flex-wrap items-center gap-3 p-4">
            <form onSubmit={(e) => { e.preventDefault(); applyFilters({ search, page: '1' }); }} className="flex flex-1 min-w-64 gap-2">
              <div className="relative flex-1">
                <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input className="pl-9" placeholder="Search code or name..." value={search} onChange={e => setSearch(e.target.value)} />
              </div>
              <Button type="submit" variant="secondary">Search</Button>
            </form>

            <Select value={filters.status ?? 'all'} onValueChange={v => applyFilters({ status: v === 'all' ? '' : v, page: '1' })}>
              <SelectTrigger className="w-40"><SelectValue placeholder="Status" /></SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Status</SelectItem>
                <SelectItem value="ACTIVE">Active</SelectItem>
                <SelectItem value="DISPOSED">Disposed</SelectItem>
                <SelectItem value="UNDER_REPAIR">Under Repair</SelectItem>
              </SelectContent>
            </Select>

            <Select value={filters.category ?? 'all'} onValueChange={v => applyFilters({ category: v === 'all' ? '' : v, page: '1' })}>
              <SelectTrigger className="w-48"><SelectValue placeholder="Category" /></SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Categories</SelectItem>
                {Object.entries(categories).map(([k, v]) => (
                  <SelectItem key={k} value={k}>{v}</SelectItem>
                ))}
              </SelectContent>
            </Select>

            <Select value={filters.dep_years ?? 'all'} onValueChange={v => applyFilters({ dep_years: v === 'all' ? '' : v, page: '1' })}>
              <SelectTrigger className="w-36"><SelectValue placeholder="Dep. Years" /></SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Years</SelectItem>
                <SelectItem value="1">1 Year</SelectItem>
                <SelectItem value="2">2 Years</SelectItem>
                <SelectItem value="3">3 Years</SelectItem>
              </SelectContent>
            </Select>
          </CardContent>
        </Card>

        {/* Table */}
        <Card>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Asset</TableHead>
                <TableHead>Category</TableHead>
                <TableHead className="text-center">Dep. Yrs</TableHead>
                <TableHead>Purchase Date</TableHead>
                <TableHead className="text-right">Cost</TableHead>
                <TableHead className="text-right">Book Value</TableHead>
                <TableHead>Assigned To</TableHead>
                <TableHead>Status</TableHead>
                <TableHead className="w-20"></TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {assets.data.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={9} className="py-12 text-center text-muted-foreground">
                    No assets found.
                  </TableCell>
                </TableRow>
              ) : assets.data.map(asset => (
                <TableRow key={asset.id}>
                  <TableCell>
                    <div className="font-medium">{asset.name}</div>
                    <div className="font-mono text-xs text-muted-foreground">{asset.asset_code}</div>
                  </TableCell>
                  <TableCell className="text-sm">{categories[asset.category] ?? asset.category}</TableCell>
                  <TableCell className="text-center text-sm font-medium">{asset.depreciation_years}yr</TableCell>
                  <TableCell className="text-sm whitespace-nowrap">{formatDate(asset.purchase_date)}</TableCell>
                  <TableCell className="text-right tabular-nums text-sm">{formatCurrency(Number(asset.acquisition_cost))}</TableCell>
                  <TableCell className="text-right tabular-nums text-sm font-medium">{formatCurrency(Number(asset.current_book_value))}</TableCell>
                  <TableCell className="text-sm">{asset.assigned_user?.name ?? '—'}</TableCell>
                  <TableCell>
                    <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_COLORS[asset.status] ?? ''}`}>
                      {asset.status.replace('_', ' ')}
                    </span>
                  </TableCell>
                  <TableCell>
                    <Link href={`/inventory/assets/${asset.id}`}>
                      <Button size="sm" variant="outline">View</Button>
                    </Link>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Card>
      </div>
    </AppLayout>
  );
}

function KpiCard({ label, value, tone }: { label: string; value: string | number; tone?: 'warn' }) {
  return (
    <Card>
      <CardContent className="p-4">
        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</p>
        <p className={`mt-1 text-xl font-bold ${tone === 'warn' ? 'text-amber-700' : ''}`}>{value}</p>
      </CardContent>
    </Card>
  );
}
