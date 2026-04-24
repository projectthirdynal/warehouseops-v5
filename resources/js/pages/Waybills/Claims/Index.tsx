import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { FileText, AlertTriangle, CheckCircle, Clock, Plus, Download, ChevronDown } from 'lucide-react';
import { formatDate } from '@/lib/utils';
import { DateRangePicker, usePersistedDateRange } from '@/components/DateRangePicker';
import type { Claim, ClaimStatus, ClaimType, PaginatedResponse } from '@/types';

interface Props {
  claims: PaginatedResponse<Claim>;
  stats: {
    total: number;
    draft: number;
    pending_review: number;
    approved: number;
    rejected: number;
  };
  filters: {
    status?: string;
    type?: string;
    search?: string;
    from?: string;
    to?: string;
  };
}

const STATUS_COLORS: Record<ClaimStatus, string> = {
  DRAFT: 'bg-gray-100 text-gray-700',
  FILED: 'bg-blue-100 text-blue-700',
  UNDER_REVIEW: 'bg-yellow-100 text-yellow-800',
  APPROVED: 'bg-green-100 text-green-700',
  REJECTED: 'bg-red-100 text-red-700',
  SETTLED: 'bg-emerald-100 text-emerald-700',
};

const STATUS_LABELS: Record<ClaimStatus, string> = {
  DRAFT: 'Draft',
  FILED: 'Filed',
  UNDER_REVIEW: 'Under Review',
  APPROVED: 'Approved',
  REJECTED: 'Rejected',
  SETTLED: 'Settled',
};

const TYPE_LABELS: Record<ClaimType, string> = {
  LOST: 'Lost Parcel',
  DAMAGED: 'Damaged',
  BEYOND_SLA: 'Beyond SLA',
};

const TYPE_COLORS: Record<ClaimType, string> = {
  LOST: 'bg-red-100 text-red-700',
  DAMAGED: 'bg-orange-100 text-orange-700',
  BEYOND_SLA: 'bg-blue-100 text-blue-700',
};

export default function ClaimsIndex({ claims, stats, filters }: Props) {
  const [search, setSearch] = useState(filters.search ?? '');
  const dateRange = usePersistedDateRange('claims-index-range', filters.from, filters.to);

  function applyFilters(overrides: Record<string, string>) {
    router.get('/waybills/claims', { ...filters, ...overrides }, { preserveState: true, replace: true });
  }

  function handleSearch(e: React.FormEvent) {
    e.preventDefault();
    applyFilters({ search, page: '1' });
  }

  function exportUrl(format: string) {
    const params = new URLSearchParams({ format });
    if (filters.status) params.set('status', filters.status);
    if (filters.type) params.set('type', filters.type);
    if (filters.from) params.set('from', filters.from);
    if (filters.to) params.set('to', filters.to);
    if (filters.search) params.set('search', filters.search);
    return `/waybills/claims/export?${params.toString()}`;
  }

  return (
    <AppLayout>
      <Head title="Claims" />

      <div className="space-y-6 p-6">
        {/* Header */}
        <div className="flex flex-wrap items-center gap-3">
          <div className="flex-1 min-w-0">
            <h1 className="text-2xl font-bold">Claims</h1>
            <p className="text-sm text-muted-foreground">Manage J&T Express claims for lost or damaged parcels</p>
          </div>
          <DateRangePicker
            value={dateRange}
            storageKey="claims-index-range"
            onChange={(range) => applyFilters({ from: range.from, to: range.to, page: '1' })}
          />
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="outline">
                <Download className="mr-2 h-4 w-4" />
                Export
                <ChevronDown className="ml-1 h-3.5 w-3.5 text-muted-foreground" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem asChild>
                <a href={exportUrl('xlsx')} download>Excel (.xlsx)</a>
              </DropdownMenuItem>
              <DropdownMenuItem asChild>
                <a href={exportUrl('csv')} download>CSV</a>
              </DropdownMenuItem>
              <DropdownMenuItem asChild>
                <a href={exportUrl('pdf')} download>PDF</a>
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
          <Link href="/waybills/claims/create">
            <Button>
              <Plus className="mr-2 h-4 w-4" />
              File New Claim
            </Button>
          </Link>
        </div>

        {/* Sub-nav */}
        <div className="flex gap-2 border-b pb-2">
          <Link href="/waybills/claims">
            <Button variant="ghost" size="sm" className="font-medium border-b-2 border-primary rounded-none">
              All Claims
            </Button>
          </Link>
          <Link href="/waybills/claims/approved">
            <Button variant="ghost" size="sm">
              Approved
            </Button>
          </Link>
          <Link href="/waybills/claims/beyond-sla">
            <Button variant="ghost" size="sm">
              Beyond SLA
            </Button>
          </Link>
        </div>

        {/* Stats */}
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-xs font-medium text-muted-foreground uppercase tracking-wide">Total Claims</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="flex items-center gap-2">
                <FileText className="h-4 w-4 text-muted-foreground" />
                <span className="text-2xl font-bold">{stats.total}</span>
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-xs font-medium text-muted-foreground uppercase tracking-wide">Pending Review</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="flex items-center gap-2">
                <Clock className="h-4 w-4 text-yellow-500" />
                <span className="text-2xl font-bold text-yellow-600">{stats.pending_review}</span>
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-xs font-medium text-muted-foreground uppercase tracking-wide">Approved</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="flex items-center gap-2">
                <CheckCircle className="h-4 w-4 text-green-500" />
                <span className="text-2xl font-bold text-green-600">{stats.approved}</span>
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-xs font-medium text-muted-foreground uppercase tracking-wide">Draft</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="flex items-center gap-2">
                <AlertTriangle className="h-4 w-4 text-gray-400" />
                <span className="text-2xl font-bold text-gray-500">{stats.draft}</span>
              </div>
            </CardContent>
          </Card>
        </div>

        {/* Filters */}
        <div className="flex flex-wrap gap-3">
          <form onSubmit={handleSearch} className="flex gap-2">
            <Input
              placeholder="Claim # or waybill #..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              className="w-60"
            />
            <Button type="submit" variant="secondary" size="sm">Search</Button>
          </form>

          <Select
            value={filters.status ?? 'all'}
            onValueChange={(v) => applyFilters({ status: v === 'all' ? '' : v, page: '1' })}
          >
            <SelectTrigger className="w-40">
              <SelectValue placeholder="All Statuses" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All Statuses</SelectItem>
              <SelectItem value="DRAFT">Draft</SelectItem>
              <SelectItem value="FILED">Filed</SelectItem>
              <SelectItem value="UNDER_REVIEW">Under Review</SelectItem>
              <SelectItem value="APPROVED">Approved</SelectItem>
              <SelectItem value="REJECTED">Rejected</SelectItem>
              <SelectItem value="SETTLED">Settled</SelectItem>
            </SelectContent>
          </Select>

          <Select
            value={filters.type ?? 'all'}
            onValueChange={(v) => applyFilters({ type: v === 'all' ? '' : v, page: '1' })}
          >
            <SelectTrigger className="w-40">
              <SelectValue placeholder="All Types" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All Types</SelectItem>
              <SelectItem value="LOST">Lost Parcel</SelectItem>
              <SelectItem value="DAMAGED">Damaged</SelectItem>
              <SelectItem value="BEYOND_SLA">Beyond SLA</SelectItem>
            </SelectContent>
          </Select>
        </div>

        {/* Table */}
        <Card>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Claim #</TableHead>
                <TableHead>Waybill #</TableHead>
                <TableHead>Type</TableHead>
                <TableHead>Status</TableHead>
                <TableHead className="text-right">Claim Amount</TableHead>
                <TableHead>Filed By</TableHead>
                <TableHead>Filed Date</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {claims.data.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={7} className="py-12 text-center text-muted-foreground">
                    No claims found.
                  </TableCell>
                </TableRow>
              ) : (
                claims.data.map((claim) => (
                  <TableRow key={claim.id} className="cursor-pointer hover:bg-muted/50">
                    <TableCell>
                      <Link
                        href={`/waybills/claims/${claim.id}`}
                        className="font-mono text-sm font-medium text-primary hover:underline"
                      >
                        {claim.claim_number}
                      </Link>
                    </TableCell>
                    <TableCell>
                      <Link
                        href={`/waybills/${claim.waybill_id}`}
                        className="font-mono text-sm hover:underline"
                        onClick={(e) => e.stopPropagation()}
                      >
                        {claim.waybill?.waybill_number ?? '—'}
                      </Link>
                    </TableCell>
                    <TableCell>
                      <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${TYPE_COLORS[claim.type]}`}>
                        {TYPE_LABELS[claim.type]}
                      </span>
                    </TableCell>
                    <TableCell>
                      <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_COLORS[claim.status]}`}>
                        {STATUS_LABELS[claim.status]}
                      </span>
                    </TableCell>
                    <TableCell className="text-right font-medium">
                      ₱{Number(claim.claim_amount).toLocaleString('en-PH', { minimumFractionDigits: 2 })}
                    </TableCell>
                    <TableCell className="text-sm">{claim.filed_by_user?.name ?? '—'}</TableCell>
                    <TableCell className="text-sm text-muted-foreground">
                      {claim.filed_at ? formatDate(claim.filed_at) : '—'}
                    </TableCell>
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>
        </Card>

        {/* Pagination */}
        {claims.last_page > 1 && (
          <div className="flex justify-center gap-2">
            {Array.from({ length: claims.last_page }, (_, i) => i + 1).map((page) => (
              <Button
                key={page}
                variant={page === claims.current_page ? 'default' : 'outline'}
                size="sm"
                onClick={() => applyFilters({ page: String(page) })}
              >
                {page}
              </Button>
            ))}
          </div>
        )}
      </div>
    </AppLayout>
  );
}
