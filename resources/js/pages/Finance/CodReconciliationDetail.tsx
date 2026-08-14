import { useState } from 'react';
import { router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ArrowLeft, Search, CheckCircle, XCircle, Link2 } from 'lucide-react';
import { formatCurrency, formatDate } from '@/lib/utils';
import type { PaginatedResponse } from '@/types';

interface ReconciliationItem {
  id: number;
  order_id: number | null;
  waybill_id: number | null;
  courier_code: string;
  order_number: string | null;
  waybill_number: string | null;
  expected_cod: number;
  remitted_cod: number;
  variance: number;
  match_status: 'MATCHED' | 'UNMATCHED' | 'MANUAL_MATCH' | 'MISMATCH';
  match_type: string | null;
  matched_at: string | null;
  order?: { order_number: string; cod_amount: number; receiver_name: string } | null;
  waybill?: { waybill_number: string; amount: number } | null;
}

interface UnmatchedOrder {
  id: number;
  order_number: string;
  cod_amount: number;
  receiver_name: string;
  delivered_at: string | null;
}

interface SettlementDetail {
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
}

interface Props {
  settlement: SettlementDetail;
  items: PaginatedResponse<ReconciliationItem>;
  unmatchedOrders: UnmatchedOrder[];
}

const matchStatusCfg: Record<string, { label: string; color: string }> = {
  MATCHED: { label: 'Matched', color: 'bg-success/10 text-success' },
  UNMATCHED: { label: 'Unmatched', color: 'bg-warning/10 text-warning' },
  MANUAL_MATCH: { label: 'Manual', color: 'bg-info/10 text-info' },
  MISMATCH: { label: 'Mismatch', color: 'bg-destructive/10 text-destructive' },
};

export default function CodReconciliationDetail({ settlement, items, unmatchedOrders }: Props) {
  const [matchingItemId, setMatchingItemId] = useState<number | null>(null);

  const handleAutoMatch = () => {
    router.post(
      `/finance/cod-reconciliation/${settlement.id}/auto-match`,
      {},
      { preserveScroll: true }
    );
  };

  const handleManualMatch = (itemId: number, orderId: number) => {
    router.post(
      '/finance/cod-reconciliation/manual-match',
      { item_id: itemId, order_id: orderId },
      {
        preserveScroll: true,
        onSuccess: () => setMatchingItemId(null),
      }
    );
  };

  const handleUnmatch = (itemId: number) => {
    router.post(
      '/finance/cod-reconciliation/unmatch',
      { item_id: itemId },
      { preserveScroll: true }
    );
  };

  const handleFinalize = () => {
    if (
      confirm(
        'Finalize reconciliation? This will create financial transactions and cannot be undone.'
      )
    ) {
      router.post(
        `/finance/cod-reconciliation/${settlement.id}/finalize`,
        {},
        { preserveScroll: true }
      );
    }
  };

  const variance = Number(settlement.variance);

  return (
    <AppLayout>
      <div className="space-y-6">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            <Button
              variant="ghost"
              size="sm"
              onClick={() => router.get('/finance/cod-reconciliation')}
            >
              <ArrowLeft className="mr-1 h-4 w-4" />
              Back
            </Button>
            <div>
              <h1 className="text-xl font-bold font-display">Settlement #{settlement.id}</h1>
              <p className="text-sm text-muted-foreground">
                {settlement.courier_code} | {formatDate(settlement.period_start)} —{' '}
                {formatDate(settlement.period_end)}
              </p>
            </div>
          </div>
          <div className="flex items-center gap-2">
            {settlement.status === 'RECEIVED' && (
              <>
                <Button size="sm" variant="outline" onClick={handleAutoMatch}>
                  <Search className="mr-1 h-3.5 w-3.5" />
                  Re-run Auto-Match
                </Button>
                {settlement.unmatched_count === 0 && (
                  <Button size="sm" onClick={handleFinalize}>
                    <CheckCircle className="mr-1 h-3.5 w-3.5" />
                    Finalize
                  </Button>
                )}
              </>
            )}
            {settlement.status === 'RECONCILED' && (
              <Badge className="bg-success/10 text-success">Reconciled</Badge>
            )}
          </div>
        </div>

        {/* Summary cards */}
        <div className="grid grid-cols-2 md:grid-cols-5 gap-4">
          <Card>
            <CardContent className="p-4 text-center">
              <p className="text-lg font-bold">{formatCurrency(settlement.total_cod_collected)}</p>
              <p className="text-xs text-muted-foreground">Collected</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4 text-center">
              <p className="text-lg font-bold">{formatCurrency(settlement.expected_cod)}</p>
              <p className="text-xs text-muted-foreground">Expected</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4 text-center">
              <p
                className={`text-lg font-bold ${variance < 0 ? 'text-destructive' : 'text-success'}`}
              >
                {formatCurrency(variance)}
              </p>
              <p className="text-xs text-muted-foreground">Variance</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4 text-center">
              <p className="text-lg font-bold text-success">{settlement.matched_count}</p>
              <p className="text-xs text-muted-foreground">Matched</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4 text-center">
              <p className="text-lg font-bold text-warning">{settlement.unmatched_count}</p>
              <p className="text-xs text-muted-foreground">Unmatched</p>
            </CardContent>
          </Card>
        </div>

        {/* Unmatched orders for manual matching */}
        {settlement.status === 'RECEIVED' && unmatchedOrders.length > 0 && matchingItemId && (
          <Card>
            <CardHeader>
              <CardTitle className="text-base">Select Order to Match</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="space-y-2 max-h-60 overflow-y-auto">
                {unmatchedOrders.map((o) => (
                  <div
                    key={o.id}
                    className="flex items-center justify-between p-2 rounded-lg border text-sm hover:bg-muted/50 cursor-pointer"
                    onClick={() => handleManualMatch(matchingItemId, o.id)}
                  >
                    <div>
                      <span className="font-medium">{o.order_number}</span>
                      <span className="text-muted-foreground ml-2">{o.receiver_name}</span>
                    </div>
                    <span className="font-semibold">{formatCurrency(o.cod_amount)}</span>
                  </div>
                ))}
              </div>
              <Button
                variant="ghost"
                size="sm"
                className="mt-2"
                onClick={() => setMatchingItemId(null)}
              >
                Cancel
              </Button>
            </CardContent>
          </Card>
        )}

        {/* Reconciliation items */}
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Reconciliation Items</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="space-y-2">
              {items.data.map((item) => {
                const cfg = matchStatusCfg[item.match_status] ?? matchStatusCfg.UNMATCHED;
                const itemVariance = Number(item.variance);
                return (
                  <div
                    key={item.id}
                    className="flex items-center gap-3 p-3 rounded-lg border text-sm"
                  >
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-2">
                        <span className="font-medium">
                          {item.order_number ?? item.waybill_number ?? 'Unknown'}
                        </span>
                        <Badge className={`${cfg.color} text-[10px]`}>{cfg.label}</Badge>
                        {item.match_type && (
                          <span className="text-xs text-muted-foreground">
                            ({item.match_type.toLowerCase()})
                          </span>
                        )}
                      </div>
                      <div className="text-xs text-muted-foreground mt-0.5">
                        Expected: {formatCurrency(item.expected_cod)} | Remitted:{' '}
                        {formatCurrency(item.remitted_cod)}
                        {itemVariance !== 0 && (
                          <span
                            className={`ml-1 ${itemVariance < 0 ? 'text-destructive' : 'text-success'}`}
                          >
                            | Var: {formatCurrency(itemVariance)}
                          </span>
                        )}
                      </div>
                    </div>
                    {settlement.status === 'RECEIVED' && (
                      <div className="flex gap-1 shrink-0">
                        {item.match_status === 'UNMATCHED' && (
                          <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setMatchingItemId(item.id)}
                          >
                            <Link2 className="mr-1 h-3.5 w-3.5" />
                            Match
                          </Button>
                        )}
                        {item.match_status !== 'UNMATCHED' && (
                          <Button size="sm" variant="ghost" onClick={() => handleUnmatch(item.id)}>
                            <XCircle className="h-3.5 w-3.5" />
                          </Button>
                        )}
                      </div>
                    )}
                  </div>
                );
              })}
            </div>

            {/* Pagination */}
            {items.last_page > 1 && (
              <div className="flex justify-center gap-2 mt-4">
                {Array.from({ length: items.last_page }, (_, i) => i + 1).map((page) => (
                  <Button
                    key={page}
                    variant={page === items.current_page ? 'default' : 'outline'}
                    size="sm"
                    onClick={() =>
                      router.get(`/finance/cod-reconciliation/${settlement.id}`, { page })
                    }
                  >
                    {page}
                  </Button>
                ))}
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
