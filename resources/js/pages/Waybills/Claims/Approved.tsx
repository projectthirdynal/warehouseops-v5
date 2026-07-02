import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
import { Banknote, CheckCircle, Download, ChevronDown } from 'lucide-react';
import { formatDate } from '@/lib/utils';
import { DateRangePicker } from '@/components/DateRangePicker';
import { usePersistedDateRange } from '@/hooks/use-persisted-date-range';
import type { Claim, PaginatedResponse } from '@/types';

interface Props {
  claims: PaginatedResponse<Claim>;
  totals: {
    total_claimed: number;
    total_approved: number;
    approved_count: number;
    settled_count: number;
  };
  filters: { search?: string; from?: string; to?: string };
}

export default function ClaimsApproved({ claims, totals, filters }: Props) {
  const [search, setSearch] = useState(filters.search ?? '');
  const dateRange = usePersistedDateRange('claims-approved-range', filters.from, filters.to);

  function applyFilters(overrides: Record<string, string>) {
    router.get(
      '/waybills/claims/approved',
      { ...filters, ...overrides },
      { preserveState: true, replace: true }
    );
  }

  function handleSearch(e: React.FormEvent) {
    e.preventDefault();
    applyFilters({ search, page: '1' });
  }

  function settle(claimId: number) {
    router.post(`/waybills/claims/${claimId}/settle`);
  }

  function exportUrl(format: string) {
    const params = new URLSearchParams({ format, status: 'APPROVED' });
    if (filters.from) params.set('from', filters.from);
    if (filters.to) params.set('to', filters.to);
    if (filters.search) params.set('search', filters.search);
    return `/waybills/claims/export?${params.toString()}`;
  }

  return (
    <AppLayout>
      <Head title="Approved Claims" />

      <div className="space-y-4 p-6">
        {/* Header */}
        <div className="flex flex-wrap items-center gap-3">
          <div className="flex-1 min-w-0">
            <h1 className="text-xl font-bold font-display">Approved Claims</h1>
            <p className="text-sm text-muted-foreground">
              Claims approved or settled with J&T Express
            </p>
          </div>
          <DateRangePicker
            value={dateRange}
            storageKey="claims-approved-range"
            onChange={(range) => applyFilters({ from: range.from, to: range.to, page: '1' })}
          />
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="outline">
                <Download className="mr-1.5 h-4 w-4" />
                Export
                <ChevronDown className="ml-1 h-3.5 w-3.5 text-muted-foreground" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem
                onClick={() => {
                  window.location.href = exportUrl('xlsx');
                }}
              >
                Excel (.xlsx)
              </DropdownMenuItem>
              <DropdownMenuItem
                onClick={() => {
                  window.location.href = exportUrl('csv');
                }}
              >
                CSV
              </DropdownMenuItem>
              <DropdownMenuItem
                onClick={() => {
                  window.location.href = exportUrl('pdf');
                }}
              >
                PDF
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>

        {/* Sub-nav */}
        <div className="flex gap-2 border-b pb-2">
          <Link href="/waybills/claims">
            <Button variant="ghost" size="sm">
              All Claims
            </Button>
          </Link>
          <Link href="/waybills/claims/approved">
            <Button
              variant="ghost"
              size="sm"
              className="font-medium border-b-2 border-primary rounded-none"
            >
              Approved
            </Button>
          </Link>
          <Link href="/waybills/claims/beyond-sla">
            <Button variant="ghost" size="sm">
              Beyond SLA
            </Button>
          </Link>
        </div>

        {/* Totals */}
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
                Total Claimed
              </CardTitle>
            </CardHeader>
            <CardContent>
              <p className="text-xl font-bold">
                ₱
                {Number(totals.total_claimed).toLocaleString('en-PH', { minimumFractionDigits: 2 })}
              </p>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
                Total Approved
              </CardTitle>
            </CardHeader>
            <CardContent>
              <p className="text-xl font-bold text-success">
                ₱
                {Number(totals.total_approved).toLocaleString('en-PH', {
                  minimumFractionDigits: 2,
                })}
              </p>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
                Approved
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="flex items-center gap-2">
                <CheckCircle className="h-4 w-4 text-success" />
                <span className="text-xl font-bold font-display">{totals.approved_count}</span>
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
                Settled
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="flex items-center gap-2">
                <Banknote className="h-4 w-4 text-success" />
                <span className="text-xl font-bold font-display">{totals.settled_count}</span>
              </div>
            </CardContent>
          </Card>
        </div>

        {/* Search */}
        <form onSubmit={handleSearch} className="flex gap-2">
          <Input
            placeholder="Claim # or waybill #..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="w-60"
          />
          <Button type="submit" variant="secondary" size="sm">
            Search
          </Button>
        </form>

        {/* Table */}
        <Card>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Claim #</TableHead>
                <TableHead>Waybill #</TableHead>
                <TableHead>Type</TableHead>
                <TableHead className="text-right">Claimed</TableHead>
                <TableHead className="text-right">Approved</TableHead>
                <TableHead>J&T Reference</TableHead>
                <TableHead>Status</TableHead>
                <TableHead>Resolved</TableHead>
                <TableHead></TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {claims.data.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={9} className="py-12 text-center text-muted-foreground">
                    No approved claims yet.
                  </TableCell>
                </TableRow>
              ) : (
                claims.data.map((claim) => (
                  <TableRow key={claim.id}>
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
                      >
                        {claim.waybill?.waybill_number ?? '—'}
                      </Link>
                    </TableCell>
                    <TableCell className="text-sm">
                      {claim.type === 'LOST'
                        ? 'Lost'
                        : claim.type === 'DAMAGED'
                          ? 'Damaged'
                          : 'Beyond SLA'}
                    </TableCell>
                    <TableCell className="text-right text-sm">
                      ₱
                      {Number(claim.claim_amount).toLocaleString('en-PH', {
                        minimumFractionDigits: 2,
                      })}
                    </TableCell>
                    <TableCell className="text-right font-medium text-success">
                      ₱
                      {Number(claim.approved_amount ?? 0).toLocaleString('en-PH', {
                        minimumFractionDigits: 2,
                      })}
                    </TableCell>
                    <TableCell className="font-mono text-sm">
                      {claim.jnt_reference_number ?? '—'}
                    </TableCell>
                    <TableCell>
                      <span
                        className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${
                          claim.status === 'SETTLED'
                            ? 'bg-success/10 text-success'
                            : 'bg-success/10 text-success'
                        }`}
                      >
                        {claim.status === 'SETTLED' ? 'Settled' : 'Approved'}
                      </span>
                    </TableCell>
                    <TableCell className="text-sm text-muted-foreground">
                      {claim.resolved_at ? formatDate(claim.resolved_at) : '—'}
                    </TableCell>
                    <TableCell>
                      {claim.status === 'APPROVED' && (
                        <Button size="sm" variant="outline" onClick={() => settle(claim.id)}>
                          Mark Settled
                        </Button>
                      )}
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
