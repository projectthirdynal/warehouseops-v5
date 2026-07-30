import { useState, useEffect, useCallback } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import { toast } from 'sonner';
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
import {
  FileText,
  AlertTriangle,
  CheckCircle,
  Clock,
  Plus,
  Download,
  ChevronDown,
  Sparkles,
  Zap,
} from 'lucide-react';
import { formatDate } from '@/lib/utils';
import { DateRangePicker } from '@/components/DateRangePicker';
import { usePersistedDateRange } from '@/hooks/use-persisted-date-range';
import type { Claim, ClaimStatus, ClaimType, PaginatedResponse } from '@/types';

interface AutoCreateStats {
  auto_created: number;
  auto_draft: number;
  auto_filed: number;
  auto_resolved: number;
  auto_rejected: number;
  pending_returned: number;
  auto_create_enabled: boolean;
  total_claim_amount: number;
}

interface Props {
  claims: PaginatedResponse<Claim>;
  stats: {
    total: number;
    draft: number;
    pending_review: number;
    approved: number;
    rejected: number;
    auto_created: number;
    auto_draft: number;
    auto_filed: number;
    auto_resolved: number;
    pending_returned: number;
    auto_create_enabled: boolean;
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
  DRAFT: 'bg-muted text-muted-foreground',
  FILED: 'bg-info/10 text-info',
  UNDER_REVIEW: 'bg-warning/10 text-warning',
  APPROVED: 'bg-success/10 text-success',
  REJECTED: 'bg-destructive/10 text-destructive',
  SETTLED: 'bg-success/10 text-success',
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
  LOST: 'bg-destructive/10 text-destructive',
  DAMAGED: 'bg-warning/10 text-warning',
  BEYOND_SLA: 'bg-info/10 text-info',
};

export default function ClaimsIndex({ claims, stats, filters }: Props) {
  const [search, setSearch] = useState(filters.search ?? '');
  const dateRange = usePersistedDateRange('claims-index-range', filters.from, filters.to);
  const [autoCreateStats, setAutoCreateStats] = useState<AutoCreateStats | null>(null);
  const [bulkLoading, setBulkLoading] = useState(false);
  const [togglingAuto, setTogglingAuto] = useState(false);

  const fetchAutoCreateStats = useCallback(() => {
    axios
      .get('/waybills/claims/auto-create/stats')
      .then(({ data }) => setAutoCreateStats(data))
      .catch(() => {});
  }, []);

  useEffect(() => {
    fetchAutoCreateStats();
  }, [fetchAutoCreateStats]);

  function applyFilters(overrides: Record<string, string>) {
    router.get(
      '/waybills/claims',
      { ...filters, ...overrides },
      { preserveState: true, replace: true }
    );
  }

  function handleSearch(e: React.FormEvent) {
    e.preventDefault();
    applyFilters({ search, page: '1' });
  }

  function handleBulkAutoCreate() {
    setBulkLoading(true);
    axios
      .post('/waybills/claims/auto-create/bulk', { days: 30 })
      .then(({ data }) => {
        toast.success(data.message);
        fetchAutoCreateStats();
        router.reload({ only: ['claims', 'stats'] });
      })
      .catch(() => toast.error('Failed to bulk create claims'))
      .finally(() => setBulkLoading(false));
  }

  function handleToggleAutoCreate() {
    setTogglingAuto(true);
    const newEnabled = !stats.auto_create_enabled;
    axios
      .patch('/waybills/claims/auto-create/toggle', { enabled: newEnabled })
      .then(() => {
        toast.success(`Auto-create ${newEnabled ? 'enabled' : 'disabled'}`);
        router.reload({ only: ['stats'] });
      })
      .catch(() => toast.error('Failed to toggle auto-create'))
      .finally(() => setTogglingAuto(false));
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

      <div className="space-y-4 p-6">
        {/* Header */}
        <div className="flex flex-wrap items-center gap-3">
          <div className="flex-1 min-w-0">
            <h1 className="text-xl font-bold font-display">Claims</h1>
            <p className="text-sm text-muted-foreground">
              Manage J&T Express claims for lost or damaged parcels
            </p>
          </div>
          <DateRangePicker
            value={dateRange}
            storageKey="claims-index-range"
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
          <Link href="/waybills/claims/create">
            <Button>
              <Plus className="mr-1.5 h-4 w-4" />
              File New Claim
            </Button>
          </Link>
        </div>

        {/* Sub-nav */}
        <div className="flex gap-2 border-b pb-2">
          <Link href="/waybills/claims">
            <Button
              variant="ghost"
              size="sm"
              className="font-medium border-b-2 border-primary rounded-none"
            >
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
              <CardTitle className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
                Total Claims
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="flex items-center gap-2">
                <FileText className="h-4 w-4 text-muted-foreground" />
                <span className="text-xl font-bold font-display">{stats.total}</span>
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
                Pending Review
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="flex items-center gap-2">
                <Clock className="h-4 w-4 text-warning" />
                <span className="text-xl font-bold font-display text-warning">
                  {stats.pending_review}
                </span>
              </div>
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
                <span className="text-xl font-bold font-display text-success">
                  {stats.approved}
                </span>
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
                Draft
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="flex items-center gap-2">
                <AlertTriangle className="h-4 w-4 text-muted-foreground" />
                <span className="text-xl font-bold font-display text-muted-foreground">
                  {stats.draft}
                </span>
              </div>
            </CardContent>
          </Card>
        </div>

        {/* Auto-Create Stats */}
        {autoCreateStats && (
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="flex items-center gap-2 text-sm font-medium">
                <Sparkles className="h-4 w-4 text-primary" />
                Claim Auto-Creation
              </CardTitle>
              <div className="flex items-center gap-2">
                <Button
                  variant={stats.auto_create_enabled ? 'default' : 'outline'}
                  size="sm"
                  onClick={handleToggleAutoCreate}
                  disabled={togglingAuto}
                >
                  {stats.auto_create_enabled ? 'Auto-Create ON' : 'Auto-Create OFF'}
                </Button>
                {autoCreateStats.pending_returned > 0 && (
                  <Button
                    variant="secondary"
                    size="sm"
                    onClick={handleBulkAutoCreate}
                    disabled={bulkLoading}
                  >
                    {bulkLoading ? (
                      <>
                        <Zap className="h-4 w-4 mr-1 animate-pulse" />
                        Creating...
                      </>
                    ) : (
                      <>
                        <Zap className="h-4 w-4 mr-1" />
                        Bulk Create ({autoCreateStats.pending_returned} pending)
                      </>
                    )}
                  </Button>
                )}
              </div>
            </CardHeader>
            <CardContent>
              <div className="grid grid-cols-2 gap-4 sm:grid-cols-5">
                <div>
                  <p className="text-xs text-muted-foreground uppercase tracking-wide">
                    Auto-Created
                  </p>
                  <p className="text-lg font-bold font-display">{autoCreateStats.auto_created}</p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground uppercase tracking-wide">
                    Auto Drafts
                  </p>
                  <p className="text-lg font-bold font-display text-muted-foreground">
                    {autoCreateStats.auto_draft}
                  </p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground uppercase tracking-wide">
                    Auto Filed
                  </p>
                  <p className="text-lg font-bold font-display text-info">
                    {autoCreateStats.auto_filed}
                  </p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground uppercase tracking-wide">
                    Auto Resolved
                  </p>
                  <p className="text-lg font-bold font-display text-success">
                    {autoCreateStats.auto_resolved}
                  </p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground uppercase tracking-wide">
                    Pending Returned
                  </p>
                  <p className="text-lg font-bold font-display text-warning">
                    {autoCreateStats.pending_returned}
                  </p>
                </div>
              </div>
              {autoCreateStats.total_claim_amount > 0 && (
                <p className="mt-3 text-xs text-muted-foreground">
                  Total auto-created claim amount: ₱
                  {Number(autoCreateStats.total_claim_amount).toLocaleString('en-PH', {
                    minimumFractionDigits: 2,
                  })}
                </p>
              )}
            </CardContent>
          </Card>
        )}

        {/* Filters */}
        <div className="flex flex-wrap gap-3">
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
                      <div className="flex items-center gap-2">
                        <Link
                          href={`/waybills/claims/${claim.id}`}
                          className="font-mono text-sm font-medium text-primary hover:underline"
                        >
                          {claim.claim_number}
                        </Link>
                        {claim.auto_created && (
                          <span className="inline-flex items-center gap-0.5 rounded-full bg-primary/10 px-1.5 py-0.5 text-xs font-medium text-primary">
                            <Sparkles className="h-3 w-3" />
                            Auto
                          </span>
                        )}
                      </div>
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
                      <span
                        className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${TYPE_COLORS[claim.type]}`}
                      >
                        {TYPE_LABELS[claim.type]}
                      </span>
                    </TableCell>
                    <TableCell>
                      <span
                        className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_COLORS[claim.status]}`}
                      >
                        {STATUS_LABELS[claim.status]}
                      </span>
                    </TableCell>
                    <TableCell className="text-right font-medium">
                      ₱
                      {Number(claim.claim_amount).toLocaleString('en-PH', {
                        minimumFractionDigits: 2,
                      })}
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
