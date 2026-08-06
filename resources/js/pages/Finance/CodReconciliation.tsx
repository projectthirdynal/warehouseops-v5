import { router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Search, CheckCircle } from 'lucide-react';
import { formatCurrency, formatDate } from '@/lib/utils';
import type { PaginatedResponse } from '@/types';

interface Settlement {
  id: number;
  courier_code: string;
  reference_number: string | null;
  period_start: string;
  period_end: string;
  total_cod_collected: number;
  expected_cod: number;
  courier_fee: number;
  net_amount: number;
  variance: number;
  order_count: number;
  matched_count: number;
  unmatched_count: number;
  status: 'RECEIVED' | 'RECONCILED';
  received_at: string | null;
  reconciled_at: string | null;
  reconciled_by?: { name: string } | null;
  created_at: string;
}

interface ReconciliationStats {
  total_expected: number;
  total_collected: number;
  total_variance: number;
  pending_reconciliation: number;
  reconciled_count: number;
  unmatched_items: number;
  mismatch_items: number;
}

interface Props {
  settlements: PaginatedResponse<Settlement>;
  stats: ReconciliationStats;
  filters: { status?: string; courier_code?: string };
}

const statusCfg: Record<string, { label: string; color: string }> = {
  RECEIVED: { label: 'Received', color: 'bg-warning/10 text-warning' },
  RECONCILED: { label: 'Reconciled', color: 'bg-success/10 text-success' },
};

export default function CodReconciliation({ settlements, stats, filters }: Props) {
  const handleAutoMatch = (id: number) => {
    router.post(`/finance/cod-reconciliation/${id}/auto-match`, {}, { preserveScroll: true });
  };

  const handleFinalize = (id: number) => {
    if (
      confirm(
        'Finalize reconciliation? This will create financial transactions and cannot be undone.'
      )
    ) {
      router.post(`/finance/cod-reconciliation/${id}/finalize`, {}, { preserveScroll: true });
    }
  };

  return (
    <AppLayout>
      <div className="space-y-6">
        <div>
          <h1 className="text-xl font-bold font-display">COD Reconciliation</h1>
          <p className="text-sm text-muted-foreground">
            Auto-match courier remittances against delivered waybills
          </p>
        </div>

        {/* Stats */}
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          <Card>
            <CardContent className="p-4 text-center">
              <p className="text-xl font-bold">{formatCurrency(stats.total_expected)}</p>
              <p className="text-xs text-muted-foreground">Total Expected COD</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4 text-center">
              <p className="text-xl font-bold">{formatCurrency(stats.total_collected)}</p>
              <p className="text-xs text-muted-foreground">Total Collected</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4 text-center">
              <p
                className={`text-xl font-bold ${stats.total_variance < 0 ? 'text-destructive' : 'text-success'}`}
              >
                {formatCurrency(stats.total_variance)}
              </p>
              <p className="text-xs text-muted-foreground">Total Variance</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4 text-center">
              <p className="text-xl font-bold text-warning">{stats.pending_reconciliation}</p>
              <p className="text-xs text-muted-foreground">Pending Reconciliation</p>
              <div className="text-xs text-muted-foreground mt-0.5">
                {stats.unmatched_items} unmatched | {stats.mismatch_items} mismatch
              </div>
            </CardContent>
          </Card>
        </div>

        {/* Filters */}
        <div className="flex items-center gap-3">
          <select
            value={filters.status || ''}
            onChange={(e) =>
              router.get(
                '/finance/cod-reconciliation',
                { ...filters, status: e.target.value || undefined },
                { preserveState: true }
              )
            }
            className="border rounded-lg px-3 py-2 text-sm"
          >
            <option value="">All Status</option>
            <option value="RECEIVED">Received (Pending)</option>
            <option value="RECONCILED">Reconciled</option>
          </select>
          <select
            value={filters.courier_code || ''}
            onChange={(e) =>
              router.get(
                '/finance/cod-reconciliation',
                { ...filters, courier_code: e.target.value || undefined },
                { preserveState: true }
              )
            }
            className="border rounded-lg px-3 py-2 text-sm"
          >
            <option value="">All Couriers</option>
            <option value="FLASH">Flash Express</option>
            <option value="JNT">J&T Express</option>
          </select>
        </div>

        {/* Settlement list */}
        <div className="space-y-3">
          {settlements.data.length === 0 ? (
            <Card>
              <CardContent className="py-12 text-center text-muted-foreground">
                <Search className="h-10 w-10 mx-auto mb-3 opacity-30" />
                <p>
                  No settlements ready for reconciliation. Mark settlements as "Received" first.
                </p>
              </CardContent>
            </Card>
          ) : (
            settlements.data.map((s) => {
              const cfg = statusCfg[s.status] ?? statusCfg.RECEIVED;
              const variance = Number(s.variance);
              return (
                <Card key={s.id}>
                  <CardContent className="p-4">
                    <div className="flex items-center justify-between gap-4">
                      <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-2">
                          <a
                            href={`/finance/cod-reconciliation/${s.id}`}
                            className="font-semibold hover:underline"
                          >
                            Settlement #{s.id}
                          </a>
                          <Badge variant="outline">{s.courier_code}</Badge>
                          <Badge className={`${cfg.color} text-[10px]`}>{cfg.label}</Badge>
                          {s.reference_number && (
                            <span className="text-xs text-muted-foreground font-mono">
                              {s.reference_number}
                            </span>
                          )}
                        </div>
                        <div className="text-xs text-muted-foreground mt-1">
                          {formatDate(s.period_start)} — {formatDate(s.period_end)} |{' '}
                          {s.matched_count}/{s.order_count} matched
                          {s.unmatched_count > 0 && (
                            <span className="text-destructive">
                              {' '}
                              | {s.unmatched_count} unmatched
                            </span>
                          )}
                          {s.reconciled_by && ` | by ${s.reconciled_by.name}`}
                        </div>
                      </div>
                      <div className="text-right shrink-0">
                        <p className="font-semibold">{formatCurrency(s.total_cod_collected)}</p>
                        <p className="text-xs text-muted-foreground">
                          Expected: {formatCurrency(s.expected_cod)}
                        </p>
                        {variance !== 0 && (
                          <p
                            className={`text-xs font-medium ${variance < 0 ? 'text-destructive' : 'text-success'}`}
                          >
                            Variance: {formatCurrency(variance)}
                          </p>
                        )}
                      </div>
                      <div className="flex gap-1 shrink-0">
                        {s.status === 'RECEIVED' && (
                          <>
                            <Button
                              size="sm"
                              variant="outline"
                              onClick={() => handleAutoMatch(s.id)}
                            >
                              <Search className="mr-1 h-3.5 w-3.5" />
                              Auto-Match
                            </Button>
                            {s.unmatched_count === 0 && (
                              <Button size="sm" onClick={() => handleFinalize(s.id)}>
                                <CheckCircle className="mr-1 h-3.5 w-3.5" />
                                Finalize
                              </Button>
                            )}
                          </>
                        )}
                      </div>
                    </div>
                  </CardContent>
                </Card>
              );
            })
          )}
        </div>

        {/* Pagination */}
        {settlements.last_page > 1 && (
          <div className="flex justify-center gap-2">
            {Array.from({ length: settlements.last_page }, (_, i) => i + 1).map((page) => (
              <Button
                key={page}
                variant={page === settlements.current_page ? 'default' : 'outline'}
                size="sm"
                onClick={() => router.get('/finance/cod-reconciliation', { ...filters, page })}
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
