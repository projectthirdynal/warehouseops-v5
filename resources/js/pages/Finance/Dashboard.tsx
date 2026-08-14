import { Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
  ArrowRight,
  Building2,
  DollarSign,
  Package,
  TrendingDown,
  TrendingUp,
  Truck,
  Users,
} from 'lucide-react';
import { formatCurrency } from '@/lib/utils';

interface CashFlow {
  inflows: {
    revenue: number;
    cod_received: number;
    gateway: number;
    invoice_payments: number;
    total: number;
  };
  outflows: {
    supplier_payments: number;
    shipping: number;
    commissions: number;
    courier_fees: number;
    total: number;
  };
  net_cash_flow: number;
  trend: { date: string; inflow: number; outflow: number; net: number }[];
}

interface PlTrendPoint {
  month: string;
  revenue: number;
  cogs: number;
  gross_profit: number;
  shipping: number;
  commissions: number;
  net_profit: number;
  margin: number;
}

interface BalanceSheet {
  assets: {
    inventory: number;
    accounts_receivable: number;
    cod_in_transit: number;
    capex_assets: number;
    cash_on_hand: number;
    total: number;
  };
  liabilities: { accounts_payable: number; commissions_payable: number; total: number };
  equity: number;
  total: number;
}

interface RevenueTrends {
  current: { gross_revenue: number; refunds: number; net_revenue: number };
  previous: { gross_revenue: number; refunds: number; net_revenue: number };
  growth_pct: number;
  trend: { date: string; revenue: number; refunds: number; net: number }[];
  by_source: { channel: string; revenue: number; orders: number }[];
}

interface EnhancedData {
  cash_flow: CashFlow;
  pl_trend: PlTrendPoint[];
  balance_sheet: BalanceSheet;
  revenue_trends: RevenueTrends;
}

interface Props {
  summary: {
    gross_revenue: number;
    refunds: number;
    net_revenue: number;
    cogs: number;
    gross_profit: number;
    shipping_costs: number;
    commissions: number;
    net_profit: number;
    margin: number;
    orders_delivered: number;
    orders_returned: number;
  };
  dailyRevenue: { date: string; total: number }[];
  commissionStats: {
    pending_total: number;
    pending_count: number;
    approved_total: number;
    paid_this_month: number;
  };
  codStats: { pending: number; received_this_month: number };
  enhanced?: EnhancedData;
  filters: { from: string; to: string };
}

function FlowRow({ label, value, negative }: { label: string; value: number; negative?: boolean }) {
  return (
    <div className="flex justify-between">
      <span className="text-muted-foreground">{label}</span>
      <span className={negative ? 'text-destructive' : ''}>
        {negative ? '-' : ''}
        {formatCurrency(Math.abs(value))}
      </span>
    </div>
  );
}

export default function FinanceDashboard({
  summary,
  dailyRevenue,
  commissionStats,
  codStats,
  enhanced,
  filters,
}: Props) {
  const maxRevenue = Math.max(...dailyRevenue.map((d) => d.total), 1);
  const cf = enhanced?.cash_flow;
  const plTrend = enhanced?.pl_trend ?? [];
  const bs = enhanced?.balance_sheet;
  const rt = enhanced?.revenue_trends;
  const maxTrendNet = Math.max(...(rt?.trend.map((t) => Math.max(t.net, 1)) ?? [1]));
  const maxCfNet = Math.max(...(cf?.trend.map((t) => Math.max(Math.abs(t.net), 1)) ?? [1]));
  const maxPlProfit = Math.max(...plTrend.map((t) => Math.max(Math.abs(t.net_profit), 1)), 1);

  return (
    <AppLayout>
      <div className="space-y-6">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-xl font-bold font-display">Finance Dashboard</h1>
            <p className="text-sm text-muted-foreground">
              Cash flow · P&L · Balance sheet · Revenue trends
            </p>
          </div>
          <div className="flex items-center gap-2">
            <input
              type="date"
              value={filters.from}
              onChange={(e) =>
                router.get(
                  '/finance',
                  { from: e.target.value, to: filters.to },
                  { preserveState: true }
                )
              }
              className="border rounded-lg px-3 py-2 text-sm"
            />
            <span className="text-muted-foreground">to</span>
            <input
              type="date"
              value={filters.to}
              onChange={(e) =>
                router.get(
                  '/finance',
                  { from: filters.from, to: e.target.value },
                  { preserveState: true }
                )
              }
              className="border rounded-lg px-3 py-2 text-sm"
            />
          </div>
        </div>

        {/* P&L Summary Cards */}
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center gap-3">
                <div className="p-2 rounded-lg bg-success/10">
                  <DollarSign className="h-5 w-5 text-success" />
                </div>
                <div>
                  <p className="text-xl font-bold text-success">
                    {formatCurrency(summary.net_revenue)}
                  </p>
                  <p className="text-xs text-muted-foreground">Net Revenue</p>
                </div>
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center gap-3">
                <div className="p-2 rounded-lg bg-info/10">
                  <TrendingUp className="h-5 w-5 text-info" />
                </div>
                <div>
                  <p className="text-xl font-bold text-info">
                    {formatCurrency(summary.gross_profit)}
                  </p>
                  <p className="text-xs text-muted-foreground">Gross Profit</p>
                </div>
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center gap-3">
                <div
                  className={`p-2 rounded-lg ${summary.net_profit >= 0 ? 'bg-success/10' : 'bg-destructive/10'}`}
                >
                  {summary.net_profit >= 0 ? (
                    <TrendingUp className="h-5 w-5 text-success" />
                  ) : (
                    <TrendingDown className="h-5 w-5 text-destructive" />
                  )}
                </div>
                <div>
                  <p
                    className={`text-xl font-bold ${summary.net_profit >= 0 ? 'text-success' : 'text-destructive'}`}
                  >
                    {formatCurrency(summary.net_profit)}
                  </p>
                  <p className="text-xs text-muted-foreground">Net Profit ({summary.margin}%)</p>
                </div>
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center gap-3">
                <div className="p-2 rounded-lg bg-primary/10">
                  <Package className="h-5 w-5 text-primary" />
                </div>
                <div>
                  <p className="text-xl font-bold">{summary.orders_delivered}</p>
                  <p className="text-xs text-muted-foreground">
                    Delivered ({summary.orders_returned} returned)
                  </p>
                </div>
              </div>
            </CardContent>
          </Card>
        </div>

        {/* Revenue Trends with Comparison */}
        {rt && (
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <Card className="lg:col-span-2">
              <CardHeader>
                <div className="flex items-center justify-between">
                  <CardTitle className="text-base">Revenue Trends</CardTitle>
                  <span
                    className={`text-sm font-medium ${rt.growth_pct >= 0 ? 'text-success' : 'text-destructive'}`}
                  >
                    {rt.growth_pct >= 0 ? '+' : ''}
                    {rt.growth_pct}% vs prev
                  </span>
                </div>
              </CardHeader>
              <CardContent>
                {rt.trend.length === 0 ? (
                  <p className="text-sm text-muted-foreground text-center py-8">No data.</p>
                ) : (
                  <div className="flex items-end gap-1 h-48">
                    {rt.trend.map((d, i) => (
                      <div
                        key={i}
                        className="flex-1 flex flex-col items-center gap-1 group relative"
                        style={{ minWidth: '8px' }}
                      >
                        <div
                          className="w-full bg-primary/80 rounded-t hover:bg-primary transition-colors min-h-[2px]"
                          style={{ height: `${(d.net / maxTrendNet) * 100}%` }}
                        />
                        {i % Math.ceil(rt.trend.length / 10) === 0 && (
                          <span className="text-[8px] text-muted-foreground whitespace-nowrap">
                            {d.date}
                          </span>
                        )}
                        <div className="absolute bottom-full mb-1 hidden group-hover:block bg-foreground text-background text-[10px] px-2 py-1 rounded whitespace-nowrap z-10">
                          {d.date}: Rev {formatCurrency(d.revenue)} · Net {formatCurrency(d.net)}
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </CardContent>
            </Card>
            <Card>
              <CardHeader>
                <CardTitle className="text-base">Revenue by Source</CardTitle>
              </CardHeader>
              <CardContent className="space-y-2 text-sm">
                {rt.by_source.length === 0 ? (
                  <p className="text-muted-foreground text-center py-4">No data.</p>
                ) : (
                  rt.by_source.map((s) => (
                    <div key={s.channel} className="flex justify-between">
                      <span className="text-muted-foreground">{s.channel}</span>
                      <div className="text-right">
                        <p className="font-medium">{formatCurrency(s.revenue)}</p>
                        <p className="text-xs text-muted-foreground">{s.orders} orders</p>
                      </div>
                    </div>
                  ))
                )}
              </CardContent>
            </Card>
          </div>
        )}

        {/* Cash Flow */}
        {cf && (
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <Card className="lg:col-span-2">
              <CardHeader>
                <div className="flex items-center justify-between">
                  <CardTitle className="text-base">Cash Flow</CardTitle>
                  <span
                    className={`text-sm font-medium ${cf.net_cash_flow >= 0 ? 'text-success' : 'text-destructive'}`}
                  >
                    Net: {formatCurrency(cf.net_cash_flow)}
                  </span>
                </div>
              </CardHeader>
              <CardContent>
                <div className="flex items-end gap-1 h-40">
                  {cf.trend.map((d, i) => (
                    <div
                      key={i}
                      className="flex-1 flex flex-col items-center justify-end gap-1 group relative"
                      style={{ minWidth: '8px' }}
                    >
                      <div
                        className={`w-full rounded-t min-h-[2px] ${d.net >= 0 ? 'bg-success/70' : 'bg-destructive/70'}`}
                        style={{ height: `${(Math.abs(d.net) / maxCfNet) * 100}%` }}
                      />
                      {i % Math.ceil(cf.trend.length / 10) === 0 && (
                        <span className="text-[8px] text-muted-foreground whitespace-nowrap">
                          {d.date}
                        </span>
                      )}
                      <div className="absolute bottom-full mb-1 hidden group-hover:block bg-foreground text-background text-[10px] px-2 py-1 rounded whitespace-nowrap z-10">
                        {d.date}: In {formatCurrency(d.inflow)} · Out -{formatCurrency(d.outflow)}
                      </div>
                    </div>
                  ))}
                </div>
              </CardContent>
            </Card>
            <Card>
              <CardHeader>
                <CardTitle className="text-base">Cash Flow Detail</CardTitle>
              </CardHeader>
              <CardContent className="space-y-3 text-sm">
                <div>
                  <p className="text-xs font-semibold text-success mb-1">INFLOWS</p>
                  <div className="space-y-1">
                    <FlowRow label="Revenue" value={cf.inflows.revenue} />
                    <FlowRow label="COD Received" value={cf.inflows.cod_received} />
                    <FlowRow label="Gateway" value={cf.inflows.gateway} />
                    <FlowRow label="Invoice Payments" value={cf.inflows.invoice_payments} />
                    <div className="border-t pt-1 flex justify-between font-medium text-success">
                      <span>Total Inflows</span>
                      <span>{formatCurrency(cf.inflows.total)}</span>
                    </div>
                  </div>
                </div>
                <div>
                  <p className="text-xs font-semibold text-destructive mb-1">OUTFLOWS</p>
                  <div className="space-y-1">
                    <FlowRow
                      label="Supplier Payments"
                      value={-cf.outflows.supplier_payments}
                      negative
                    />
                    <FlowRow label="Shipping" value={-cf.outflows.shipping} negative />
                    <FlowRow label="Commissions" value={-cf.outflows.commissions} negative />
                    <FlowRow label="Courier Fees" value={-cf.outflows.courier_fees} negative />
                    <div className="border-t pt-1 flex justify-between font-medium text-destructive">
                      <span>Total Outflows</span>
                      <span>-{formatCurrency(cf.outflows.total)}</span>
                    </div>
                  </div>
                </div>
                <div className="border-t pt-2 flex justify-between font-bold text-base">
                  <span>Net Cash Flow</span>
                  <span className={cf.net_cash_flow >= 0 ? 'text-success' : 'text-destructive'}>
                    {formatCurrency(cf.net_cash_flow)}
                  </span>
                </div>
              </CardContent>
            </Card>
          </div>
        )}

        {/* P&L Trend + Balance Sheet */}
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <Card>
            <CardHeader>
              <CardTitle className="text-base">P&L Trend (6 Months)</CardTitle>
            </CardHeader>
            <CardContent>
              {plTrend.length === 0 ? (
                <p className="text-sm text-muted-foreground text-center py-8">No P&L trend data.</p>
              ) : (
                <div className="space-y-3">
                  <div className="flex items-end gap-2 h-32">
                    {plTrend.map((t, i) => (
                      <div
                        key={i}
                        className="flex-1 flex flex-col items-center gap-1 group relative"
                      >
                        <div
                          className={`w-full rounded-t min-h-[2px] ${t.net_profit >= 0 ? 'bg-success/70' : 'bg-destructive/70'}`}
                          style={{ height: `${(Math.abs(t.net_profit) / maxPlProfit) * 100}%` }}
                        />
                        <span className="text-[8px] text-muted-foreground whitespace-nowrap">
                          {t.month}
                        </span>
                        <div className="absolute bottom-full mb-1 hidden group-hover:block bg-foreground text-background text-[10px] px-2 py-1 rounded whitespace-nowrap z-10">
                          {t.month}: Rev {formatCurrency(t.revenue)} · Net{' '}
                          {formatCurrency(t.net_profit)} · {t.margin}%
                        </div>
                      </div>
                    ))}
                  </div>
                  <div className="overflow-x-auto">
                    <table className="w-full text-xs">
                      <thead className="border-b bg-muted/50">
                        <tr>
                          <th className="px-2 py-1 text-left">Month</th>
                          <th className="px-2 py-1 text-right">Revenue</th>
                          <th className="px-2 py-1 text-right">COGS</th>
                          <th className="px-2 py-1 text-right">Net Profit</th>
                          <th className="px-2 py-1 text-right">Margin</th>
                        </tr>
                      </thead>
                      <tbody>
                        {plTrend.map((t, i) => (
                          <tr key={i} className="border-b last:border-0">
                            <td className="px-2 py-1 font-medium">{t.month}</td>
                            <td className="px-2 py-1 text-right">{formatCurrency(t.revenue)}</td>
                            <td className="px-2 py-1 text-right text-destructive">
                              -{formatCurrency(t.cogs)}
                            </td>
                            <td
                              className={`px-2 py-1 text-right font-medium ${t.net_profit >= 0 ? 'text-success' : 'text-destructive'}`}
                            >
                              {formatCurrency(t.net_profit)}
                            </td>
                            <td className="px-2 py-1 text-right text-muted-foreground">
                              {t.margin}%
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </div>
              )}
            </CardContent>
          </Card>

          {bs && (
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2 text-base">
                  <Building2 className="h-4 w-4" />
                  Balance Sheet
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-4 text-sm">
                <div>
                  <p className="text-xs font-semibold text-success mb-1">ASSETS</p>
                  <div className="space-y-1">
                    <FlowRow label="Inventory" value={bs.assets.inventory} />
                    <FlowRow label="Accounts Receivable" value={bs.assets.accounts_receivable} />
                    <FlowRow label="COD In Transit" value={bs.assets.cod_in_transit} />
                    <FlowRow label="Fixed Assets" value={bs.assets.capex_assets} />
                    <FlowRow label="Cash on Hand" value={bs.assets.cash_on_hand} />
                    <div className="border-t pt-1 flex justify-between font-medium text-success">
                      <span>Total Assets</span>
                      <span>{formatCurrency(bs.assets.total)}</span>
                    </div>
                  </div>
                </div>
                <div>
                  <p className="text-xs font-semibold text-destructive mb-1">LIABILITIES</p>
                  <div className="space-y-1">
                    <FlowRow
                      label="Accounts Payable"
                      value={-bs.liabilities.accounts_payable}
                      negative
                    />
                    <FlowRow
                      label="Commissions Payable"
                      value={-bs.liabilities.commissions_payable}
                      negative
                    />
                    <div className="border-t pt-1 flex justify-between font-medium text-destructive">
                      <span>Total Liabilities</span>
                      <span>-{formatCurrency(bs.liabilities.total)}</span>
                    </div>
                  </div>
                </div>
                <div className="border-t pt-2 flex justify-between font-bold text-base">
                  <span>Equity</span>
                  <span className={bs.equity >= 0 ? 'text-success' : 'text-destructive'}>
                    {formatCurrency(bs.equity)}
                  </span>
                </div>
              </CardContent>
            </Card>
          )}
        </div>

        {/* Daily Revenue + P&L Breakdown */}
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <Card className="lg:col-span-2">
            <CardHeader>
              <CardTitle className="text-base">Daily Revenue (Last 30 Days)</CardTitle>
            </CardHeader>
            <CardContent>
              {dailyRevenue.length === 0 ? (
                <p className="text-sm text-muted-foreground text-center py-8">
                  No revenue data yet.
                </p>
              ) : (
                <div className="flex items-end gap-1 h-48">
                  {dailyRevenue.map((d, i) => (
                    <div key={i} className="flex-1 flex flex-col items-center gap-1 group relative">
                      <div
                        className="w-full bg-primary/80 rounded-t hover:bg-primary transition-colors min-h-[2px]"
                        style={{ height: `${(d.total / maxRevenue) * 100}%` }}
                      />
                      <span className="text-[8px] text-muted-foreground rotate-[-45deg] origin-top-left whitespace-nowrap">
                        {i % 5 === 0 ? d.date : ''}
                      </span>
                      <div className="absolute bottom-full mb-1 hidden group-hover:block bg-foreground text-background text-[10px] px-2 py-1 rounded whitespace-nowrap z-10">
                        {d.date}: {formatCurrency(d.total)}
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </CardContent>
          </Card>
          <Card>
            <CardHeader>
              <CardTitle className="text-base">P&L Breakdown</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3 text-sm">
              <div className="flex justify-between">
                <span>Gross Revenue</span>
                <span className="font-semibold">{formatCurrency(summary.gross_revenue)}</span>
              </div>
              <div className="flex justify-between text-destructive">
                <span>Refunds</span>
                <span>-{formatCurrency(summary.refunds)}</span>
              </div>
              <div className="border-t pt-2 flex justify-between font-medium">
                <span>Net Revenue</span>
                <span>{formatCurrency(summary.net_revenue)}</span>
              </div>
              <div className="flex justify-between text-destructive">
                <span>COGS</span>
                <span>-{formatCurrency(summary.cogs)}</span>
              </div>
              <div className="border-t pt-2 flex justify-between font-medium">
                <span>Gross Profit</span>
                <span>{formatCurrency(summary.gross_profit)}</span>
              </div>
              <div className="flex justify-between text-destructive">
                <span>Shipping</span>
                <span>-{formatCurrency(summary.shipping_costs)}</span>
              </div>
              <div className="flex justify-between text-destructive">
                <span>Commissions</span>
                <span>-{formatCurrency(summary.commissions)}</span>
              </div>
              <div className="border-t pt-2 flex justify-between font-bold text-lg">
                <span>Net Profit</span>
                <span className={summary.net_profit >= 0 ? 'text-success' : 'text-destructive'}>
                  {formatCurrency(summary.net_profit)}
                </span>
              </div>
            </CardContent>
          </Card>
        </div>

        {/* Quick links */}
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-3">
                  <Users className="h-5 w-5 text-muted-foreground" />
                  <div>
                    <p className="font-semibold">Commissions</p>
                    <p className="text-sm text-muted-foreground">
                      {commissionStats.pending_count} pending (
                      {formatCurrency(commissionStats.pending_total)})
                    </p>
                  </div>
                </div>
                <Link href="/finance/commissions">
                  <Button variant="outline" size="sm">
                    Manage <ArrowRight className="ml-1 h-3 w-3" />
                  </Button>
                </Link>
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-3">
                  <Truck className="h-5 w-5 text-muted-foreground" />
                  <div>
                    <p className="font-semibold">COD Settlements</p>
                    <p className="text-sm text-muted-foreground">
                      {formatCurrency(codStats.pending)} pending
                    </p>
                  </div>
                </div>
                <Link href="/finance/cod">
                  <Button variant="outline" size="sm">
                    Manage <ArrowRight className="ml-1 h-3 w-3" />
                  </Button>
                </Link>
              </div>
            </CardContent>
          </Card>
        </div>
      </div>
    </AppLayout>
  );
}
