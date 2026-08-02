import { useState, useCallback, useEffect } from 'react';
import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import { toast } from 'sonner';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Checkbox } from '@/components/ui/checkbox';
import {
  ArrowLeft,
  Send,
  CheckCircle,
  XCircle,
  Loader2,
  Package,
  Clock,
  TrendingUp,
} from 'lucide-react';
import { formatDate } from '@/lib/utils';
import type { PaginatedResponse } from '@/types';

interface Courier {
  id: number;
  code: string;
  name: string;
}

interface PendingWaybill {
  id: number;
  waybill_number: string;
  receiver_name: string;
  receiver_phone: string;
  city: string;
  state: string;
  courier_provider: string | null;
  cod_amount: string;
  created_at: string;
}

interface BatchStats {
  pending_count: number;
  pending_by_courier: Record<string, number>;
  dispatched_today: number;
  total_dispatched: number;
}

interface DispatchResultEntry {
  waybill_id: number;
  waybill_number: string;
  receiver_name: string;
  success: boolean;
  tracking_number: string | null;
  error_message: string | null;
}

interface DispatchResult {
  total: number;
  success: number;
  failed: number;
  results: DispatchResultEntry[];
}

interface Props {
  pendingWaybills: PaginatedResponse<PendingWaybill>;
  stats: BatchStats;
  couriers: Courier[];
  filters: {
    search?: string;
  };
}

export default function BatchDispatch({ pendingWaybills, stats, couriers, filters }: Props) {
  const [search, setSearch] = useState(filters.search ?? '');
  const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set());
  const [courierCode, setCourierCode] = useState(couriers[0]?.code ?? '');
  const [dispatching, setDispatching] = useState(false);
  const [result, setResult] = useState<DispatchResult | null>(null);
  const [currentStats, setCurrentStats] = useState<BatchStats>(stats);

  const fetchStats = useCallback(() => {
    axios
      .get('/waybills/batch-dispatch/stats')
      .then(({ data }) => setCurrentStats(data))
      .catch(() => {});
  }, []);

  useEffect(() => {
    fetchStats();
  }, [fetchStats]);

  function toggleSelect(id: number) {
    setSelectedIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }
      return next;
    });
  }

  function toggleSelectAll() {
    if (selectedIds.size === pendingWaybills.data.length) {
      setSelectedIds(new Set());
    } else {
      setSelectedIds(new Set(pendingWaybills.data.map((w) => w.id)));
    }
  }

  function handleSearch(e: React.FormEvent) {
    e.preventDefault();
    window.location.href = `/waybills/batch-dispatch?search=${encodeURIComponent(search)}`;
  }

  function handleDispatch() {
    if (selectedIds.size === 0) {
      toast.warning('Select at least one waybill to dispatch.');
      return;
    }
    if (!courierCode) {
      toast.warning('Select a courier.');
      return;
    }

    setDispatching(true);
    setResult(null);

    axios
      .post('/waybills/batch-dispatch', {
        waybill_ids: Array.from(selectedIds),
        courier_code: courierCode,
      })
      .then(({ data }) => {
        if (data.result) {
          setResult(data.result);
          if (data.result.failed === 0) {
            toast.success(data.message);
          } else if (data.result.success > 0) {
            toast.warning(data.message);
          } else {
            toast.error(data.message);
          }
        } else {
          toast.success(data.message);
        }
        setSelectedIds(new Set());
        fetchStats();
      })
      .catch(() => toast.error('Failed to dispatch waybills'))
      .finally(() => setDispatching(false));
  }

  const allSelected =
    selectedIds.size === pendingWaybills.data.length && pendingWaybills.data.length > 0;

  return (
    <AppLayout>
      <Head title="Batch Dispatch" />

      <div className="space-y-4 p-6">
        <div className="flex items-center gap-3">
          <Link href="/waybills">
            <Button variant="ghost" size="sm">
              <ArrowLeft className="h-4 w-4 mr-1" />
              Back
            </Button>
          </Link>
          <div className="flex-1">
            <h1 className="text-xl font-bold font-display">Batch Dispatch</h1>
            <p className="text-sm text-muted-foreground">
              Dispatch multiple pending waybills to a courier in one operation
            </p>
          </div>
        </div>

        {/* Stats */}
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
                Pending
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="flex items-center gap-2">
                <Clock className="h-4 w-4 text-warning" />
                <span className="text-xl font-bold font-display text-warning">
                  {currentStats.pending_count}
                </span>
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
                Dispatched Today
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="flex items-center gap-2">
                <TrendingUp className="h-4 w-4 text-info" />
                <span className="text-xl font-bold font-display text-info">
                  {currentStats.dispatched_today}
                </span>
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
                Total Dispatched
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="flex items-center gap-2">
                <Package className="h-4 w-4 text-success" />
                <span className="text-xl font-bold font-display text-success">
                  {currentStats.total_dispatched}
                </span>
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
                Selected
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="flex items-center gap-2">
                <span className="text-xl font-bold font-display">{selectedIds.size}</span>
              </div>
            </CardContent>
          </Card>
        </div>

        {/* Dispatch controls */}
        <Card>
          <CardContent className="flex flex-wrap items-center gap-3 pt-4">
            <form onSubmit={handleSearch} className="flex gap-2">
              <Input
                placeholder="Search waybill or receiver..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                className="w-60"
              />
              <Button type="submit" variant="secondary" size="sm">
                Search
              </Button>
            </form>
            <Select value={courierCode} onValueChange={setCourierCode}>
              <SelectTrigger className="w-48">
                <SelectValue placeholder="Select courier" />
              </SelectTrigger>
              <SelectContent>
                {couriers.map((c) => (
                  <SelectItem key={c.id} value={c.code}>
                    {c.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <Button onClick={handleDispatch} disabled={dispatching || selectedIds.size === 0}>
              {dispatching ? (
                <>
                  <Loader2 className="h-4 w-4 mr-1 animate-spin" />
                  Dispatching {selectedIds.size}...
                </>
              ) : (
                <>
                  <Send className="h-4 w-4 mr-1" />
                  Dispatch {selectedIds.size > 0 ? `(${selectedIds.size})` : ''}
                </>
              )}
            </Button>
          </CardContent>
        </Card>

        {/* Results */}
        {result && (
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                Dispatch Results
                <span className="text-sm font-normal text-muted-foreground">
                  {result.success}/{result.total} succeeded
                </span>
              </CardTitle>
              <CardDescription>
                <span className="text-success">{result.success} succeeded</span>
                {' · '}
                <span className="text-destructive">{result.failed} failed</span>
              </CardDescription>
            </CardHeader>
            <CardContent>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Waybill #</TableHead>
                    <TableHead>Receiver</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Tracking #</TableHead>
                    <TableHead>Error</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {result.results.map((entry) => (
                    <TableRow key={entry.waybill_id}>
                      <TableCell className="font-mono text-sm">{entry.waybill_number}</TableCell>
                      <TableCell className="text-sm">{entry.receiver_name}</TableCell>
                      <TableCell>
                        {entry.success ? (
                          <span className="inline-flex items-center gap-1 rounded-full bg-success/10 px-2 py-0.5 text-xs font-medium text-success">
                            <CheckCircle className="h-3 w-3" />
                            Dispatched
                          </span>
                        ) : (
                          <span className="inline-flex items-center gap-1 rounded-full bg-destructive/10 px-2 py-0.5 text-xs font-medium text-destructive">
                            <XCircle className="h-3 w-3" />
                            Failed
                          </span>
                        )}
                      </TableCell>
                      <TableCell className="font-mono text-sm">
                        {entry.tracking_number ?? '—'}
                      </TableCell>
                      <TableCell className="text-sm text-destructive">
                        {entry.error_message ?? '—'}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        )}

        {/* Pending waybills table */}
        <Card>
          <CardHeader>
            <CardTitle>Pending Waybills</CardTitle>
            <CardDescription>
              {pendingWaybills.total} pending waybill{pendingWaybills.total !== 1 ? 's' : ''} ·{' '}
              {pendingWaybills.data.length} shown on this page
            </CardDescription>
          </CardHeader>
          <CardContent>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-12">
                    <Checkbox checked={allSelected} onCheckedChange={toggleSelectAll} />
                  </TableHead>
                  <TableHead>Waybill #</TableHead>
                  <TableHead>Receiver</TableHead>
                  <TableHead>Phone</TableHead>
                  <TableHead>City</TableHead>
                  <TableHead>Courier</TableHead>
                  <TableHead className="text-right">COD Amount</TableHead>
                  <TableHead>Created</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {pendingWaybills.data.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={8} className="py-12 text-center text-muted-foreground">
                      No pending waybills found.
                    </TableCell>
                  </TableRow>
                ) : (
                  pendingWaybills.data.map((waybill) => (
                    <TableRow key={waybill.id} className="hover:bg-muted/50">
                      <TableCell>
                        <Checkbox
                          checked={selectedIds.has(waybill.id)}
                          onCheckedChange={() => toggleSelect(waybill.id)}
                        />
                      </TableCell>
                      <TableCell className="font-mono text-sm font-medium">
                        {waybill.waybill_number}
                      </TableCell>
                      <TableCell className="text-sm">{waybill.receiver_name}</TableCell>
                      <TableCell className="text-sm">{waybill.receiver_phone}</TableCell>
                      <TableCell className="text-sm">{waybill.city ?? '—'}</TableCell>
                      <TableCell className="text-sm">{waybill.courier_provider ?? '—'}</TableCell>
                      <TableCell className="text-right text-sm">
                        ₱
                        {Number(waybill.cod_amount ?? 0).toLocaleString('en-PH', {
                          minimumFractionDigits: 2,
                        })}
                      </TableCell>
                      <TableCell className="text-sm text-muted-foreground">
                        {formatDate(waybill.created_at)}
                      </TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>
          </CardContent>
        </Card>

        {/* Pagination */}
        {pendingWaybills.last_page > 1 && (
          <div className="flex justify-center gap-2">
            {Array.from({ length: pendingWaybills.last_page }, (_, i) => i + 1).map((page) => (
              <Button
                key={page}
                variant={page === pendingWaybills.current_page ? 'default' : 'outline'}
                size="sm"
                onClick={() => {
                  const params = new URLSearchParams({ page: String(page) });
                  if (search) params.set('search', search);
                  window.location.href = `/waybills/batch-dispatch?${params.toString()}`;
                }}
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
