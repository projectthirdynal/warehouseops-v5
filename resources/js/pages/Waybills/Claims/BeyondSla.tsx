import { useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
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
import { Card } from '@/components/ui/card';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { AlertTriangle, PackageX, ScanLine, Download, ChevronDown } from 'lucide-react';
import { formatDate } from '@/lib/utils';
import { DateRangePicker, usePersistedDateRange } from '@/components/DateRangePicker';
import type { PaginatedResponse, ScanResults, Waybill, PageProps } from '@/types';

interface Props {
  waybills: PaginatedResponse<Waybill & { claims?: { id: number }[] }>;
  beyond_sla_count: number;
  filters: { search?: string; from?: string; to?: string };
}

function daysOverdue(returnedAt: string): number {
  const returned = new Date(returnedAt.includes('T') ? returnedAt : returnedAt.replace(' ', 'T') + '+08:00');
  const now = new Date();
  const diff = Math.floor((now.getTime() - returned.getTime()) / (1000 * 60 * 60 * 24));
  return Math.max(0, diff);
}

export default function BeyondSla({ waybills, beyond_sla_count, filters }: Props) {
  const { props } = usePage<PageProps & { scan_results?: ScanResults }>();
  const scanResults = props.scan_results;

  const [search, setSearch] = useState(filters.search ?? '');
  const [scanOpen, setScanOpen] = useState(false);
  const [resultsOpen, setResultsOpen] = useState(!!scanResults);
  const dateRange = usePersistedDateRange('beyond-sla-range', filters.from, filters.to);

  const scanForm = useForm({
    waybill_numbers: '',
    condition: 'GOOD',
    notes: '',
  });

  function applyFilters(overrides: Record<string, string>) {
    router.get('/waybills/claims/beyond-sla', { ...filters, ...overrides }, { preserveState: true, replace: true });
  }

  function handleSearch(e: React.FormEvent) {
    e.preventDefault();
    applyFilters({ search, page: '1' });
  }

  function exportUrl(format: string) {
    const params = new URLSearchParams({ format });
    if (filters.from) params.set('from', filters.from);
    if (filters.to) params.set('to', filters.to);
    if (filters.search) params.set('search', filters.search);
    return `/waybills/beyond-sla/export?${params.toString()}`;
  }

  function submitScan(e: React.FormEvent) {
    e.preventDefault();
    scanForm.post('/waybills/returns/scan', {
      onSuccess: () => {
        setScanOpen(false);
        setResultsOpen(true);
        scanForm.reset();
      },
    });
  }

  return (
    <AppLayout>
      <Head title="Beyond SLA" />

      <div className="space-y-6 p-6">
        {/* Header */}
        <div className="flex flex-wrap items-center gap-3">
          <div className="flex-1 min-w-0">
            <h1 className="text-2xl font-bold">Beyond SLA</h1>
            <p className="text-sm text-muted-foreground">
              Returned parcels J&T failed to deliver back by the next calendar day
            </p>
          </div>
          <DateRangePicker
            value={dateRange}
            storageKey="beyond-sla-range"
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
              <DropdownMenuItem onClick={() => { window.location.href = exportUrl('xlsx'); }}>
                Excel (.xlsx)
              </DropdownMenuItem>
              <DropdownMenuItem onClick={() => { window.location.href = exportUrl('csv'); }}>
                CSV
              </DropdownMenuItem>
              <DropdownMenuItem onClick={() => { window.location.href = exportUrl('pdf'); }}>
                PDF
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
          <Button onClick={() => setScanOpen(true)}>
            <ScanLine className="mr-2 h-4 w-4" />
            Scan Received Returns
          </Button>
        </div>

        {/* Sub-nav */}
        <div className="flex gap-2 border-b pb-2">
          <Link href="/waybills/claims">
            <Button variant="ghost" size="sm">All Claims</Button>
          </Link>
          <Link href="/waybills/claims/approved">
            <Button variant="ghost" size="sm">Approved</Button>
          </Link>
          <Link href="/waybills/claims/beyond-sla">
            <Button variant="ghost" size="sm" className="font-medium border-b-2 border-primary rounded-none">
              Beyond SLA
            </Button>
          </Link>
        </div>

        {/* Alert banner */}
        {beyond_sla_count > 0 && (
          <div className="flex items-center gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3">
            <AlertTriangle className="h-5 w-5 shrink-0 text-red-500" />
            <p className="text-sm font-medium text-red-800">
              {beyond_sla_count} parcel{beyond_sla_count !== 1 ? 's' : ''} beyond SLA — J&T is obligated to compensate for these.
            </p>
          </div>
        )}

        {/* Search */}
        <form onSubmit={handleSearch} className="flex gap-2">
          <Input
            placeholder="Waybill # or receiver name..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="w-60"
          />
          <Button type="submit" variant="secondary" size="sm">Search</Button>
        </form>

        {/* Table */}
        <Card>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Waybill #</TableHead>
                <TableHead>Receiver</TableHead>
                <TableHead>City</TableHead>
                <TableHead className="text-right">COD Amount</TableHead>
                <TableHead>Returned Date</TableHead>
                <TableHead>Days Overdue</TableHead>
                <TableHead>Existing Claims</TableHead>
                <TableHead></TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {waybills.data.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={8} className="py-12 text-center text-muted-foreground">
                    <PackageX className="mx-auto mb-2 h-8 w-8 opacity-30" />
                    No parcels beyond SLA. All returned parcels have been received.
                  </TableCell>
                </TableRow>
              ) : (
                waybills.data.map((waybill) => {
                  const overdue = waybill.returned_at ? daysOverdue(waybill.returned_at as string) : 0;
                  return (
                    <TableRow key={waybill.id}>
                      <TableCell>
                        <Link href={`/waybills/${waybill.id}`} className="font-mono text-sm font-medium hover:underline">
                          {waybill.waybill_number}
                        </Link>
                      </TableCell>
                      <TableCell className="text-sm">{waybill.receiver_name}</TableCell>
                      <TableCell className="text-sm">{waybill.city}</TableCell>
                      <TableCell className="text-right font-medium">
                        ₱{Number(waybill.cod_amount ?? waybill.amount ?? 0).toLocaleString('en-PH', { minimumFractionDigits: 2 })}
                      </TableCell>
                      <TableCell className="text-sm">
                        {waybill.returned_at ? formatDate(waybill.returned_at as string) : '—'}
                      </TableCell>
                      <TableCell>
                        <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${
                          overdue >= 3 ? 'bg-red-100 text-red-700' : 'bg-orange-100 text-orange-700'
                        }`}>
                          {overdue}d overdue
                        </span>
                      </TableCell>
                      <TableCell className="text-sm">
                        {(waybill.claims?.length ?? 0) > 0 ? (
                          <span className="text-muted-foreground">{waybill.claims?.length} claim(s)</span>
                        ) : (
                          <span className="text-muted-foreground/50">None</span>
                        )}
                      </TableCell>
                      <TableCell>
                        <Link href={`/waybills/claims/create?waybill_id=${waybill.id}&type=BEYOND_SLA`}>
                          <Button size="sm" variant="outline">
                            File Claim
                          </Button>
                        </Link>
                      </TableCell>
                    </TableRow>
                  );
                })
              )}
            </TableBody>
          </Table>
        </Card>

        {/* Pagination */}
        {waybills.last_page > 1 && (
          <div className="flex justify-center gap-2">
            {Array.from({ length: waybills.last_page }, (_, i) => i + 1).map((page) => (
              <Button
                key={page}
                variant={page === waybills.current_page ? 'default' : 'outline'}
                size="sm"
                onClick={() => applyFilters({ page: String(page) })}
              >
                {page}
              </Button>
            ))}
          </div>
        )}
      </div>

      {/* Batch scan dialog */}
      <Dialog open={scanOpen} onOpenChange={setScanOpen}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <DialogTitle>Scan Received Returns</DialogTitle>
          </DialogHeader>
          <form onSubmit={submitScan} className="space-y-4">
            <p className="text-sm text-muted-foreground">
              Enter waybill numbers of parcels physically received by your team. One per line, or paste from a spreadsheet.
            </p>
            <div className="space-y-1">
              <Label>Waybill Numbers</Label>
              <Textarea
                value={scanForm.data.waybill_numbers}
                onChange={(e) => scanForm.setData('waybill_numbers', e.target.value)}
                placeholder={'JT0000000001\nJT0000000002\nJT0000000003'}
                rows={8}
                className="font-mono text-sm"
                required
              />
              {scanForm.errors.waybill_numbers && (
                <p className="text-sm text-red-600">{scanForm.errors.waybill_numbers}</p>
              )}
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-1">
                <Label>Condition</Label>
                <Select
                  value={scanForm.data.condition}
                  onValueChange={(v) => scanForm.setData('condition', v)}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="GOOD">Good</SelectItem>
                    <SelectItem value="DAMAGED">Damaged</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-1">
                <Label>Notes <span className="text-muted-foreground">(optional)</span></Label>
                <Input
                  value={scanForm.data.notes}
                  onChange={(e) => scanForm.setData('notes', e.target.value)}
                  placeholder="Batch notes..."
                />
              </div>
            </div>
            <div className="flex gap-2 justify-end">
              <Button type="button" variant="outline" onClick={() => setScanOpen(false)}>Cancel</Button>
              <Button type="submit" disabled={scanForm.processing}>
                {scanForm.processing ? 'Processing...' : 'Mark as Received'}
              </Button>
            </div>
          </form>
        </DialogContent>
      </Dialog>

      {/* Scan results dialog */}
      {scanResults && (
        <Dialog open={resultsOpen} onOpenChange={setResultsOpen}>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>Scan Results</DialogTitle>
            </DialogHeader>
            <div className="space-y-4 text-sm">
              {scanResults.scanned.length > 0 && (
                <div className="rounded-lg bg-green-50 p-3">
                  <p className="font-medium text-green-800 mb-1">
                    ✓ {scanResults.scanned.length} received successfully
                  </p>
                  <p className="font-mono text-green-700 text-xs">{scanResults.scanned.join(', ')}</p>
                </div>
              )}
              {scanResults.already_received.length > 0 && (
                <div className="rounded-lg bg-blue-50 p-3">
                  <p className="font-medium text-blue-800 mb-1">
                    Already received ({scanResults.already_received.length})
                  </p>
                  <p className="font-mono text-blue-700 text-xs">{scanResults.already_received.join(', ')}</p>
                </div>
              )}
              {scanResults.wrong_status.length > 0 && (
                <div className="rounded-lg bg-yellow-50 p-3">
                  <p className="font-medium text-yellow-800 mb-1">
                    Wrong status — not RETURNED ({scanResults.wrong_status.length})
                  </p>
                  <p className="font-mono text-yellow-700 text-xs">{scanResults.wrong_status.join(', ')}</p>
                </div>
              )}
              {scanResults.not_found.length > 0 && (
                <div className="rounded-lg bg-red-50 p-3">
                  <p className="font-medium text-red-800 mb-1">
                    Not found ({scanResults.not_found.length})
                  </p>
                  <p className="font-mono text-red-700 text-xs">{scanResults.not_found.join(', ')}</p>
                </div>
              )}
            </div>
            <Button onClick={() => setResultsOpen(false)}>Done</Button>
          </DialogContent>
        </Dialog>
      )}
    </AppLayout>
  );
}
