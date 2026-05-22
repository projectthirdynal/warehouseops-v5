import { Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
  DollarSign,
  TrendingUp,
  TrendingDown,
  Package,
  Truck,
  Users,
  ArrowRight,
} from 'lucide-react';
import { formatCurrency } from '@/lib/utils';

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
  codStats: {
    pending: number;
    received_this_month: number;
  };
  filters: { from: string; to: string };
}

export default function FinanceDashboard({ summary, dailyRevenue, commissionStats, codStats, filters }: Props) {
  const maxRevenue = Math.max(...dailyRevenue.map((d) => d.total), 1);

  return (
    <AppLayout>
      <div className="space-y-6">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold">Finance</h1>
            <p className="text-sm text-muted-foreground">Revenue, commissions, and P&L overview</p>
          </div>
          <div className="flex items-center gap-2">
            <input
              type="date"
              value={filters.from}
              onChange={(e) => router.get('/finance', { from: e.target.value, to: filters.to }, { preserveState: true })}
              className="border rounded-lg px-3 py-2 text-sm"
            />
            <span className="text-muted-foreground">to</span>
            <input
              type="date"
              value={filters.to}
              onChange={(e) => router.get('/finance', { from: filters.from, to: e.target.value }, { preserveState: true })}
              className="border rounded-lg px-3 py-2 text-sm"
            />
          </div>
        </div>

        {/* P&L Summary */}
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center gap-3">
                <div className="p-2 rounded-lg bg-green-100">
                  <DollarSign className="h-5 w-5 text-green-600" />
                </div>
                <div>
                  <p className="text-xl font-bold text-green-600">{formatCurrency(summary.net_revenue)}</p>
                  <p className="text-xs text-muted-foreground">Net Revenue</p>
                </div>
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center gap-3">
                <div className="p-2 rounded-lg bg-blue-100">
                  <TrendingUp className="h-5 w-5 text-blue-600" />
                </div>
                <div>
                  <p className="text-xl font-bold text-blue-600">{formatCurrency(summary.gross_profit)}</p>
                  <p className="text-xs text-muted-foreground">Gross Profit</p>
                </div>
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center gap-3">
                <div className={`p-2 rounded-lg ${summary.net_profit >= 0 ? 'bg-green-100' : 'bg-red-100'}`}>
                  {summary.net_profit >= 0
                    ? <TrendingUp className="h-5 w-5 text-green-600" />
                    : <TrendingDown className="h-5 w-5 text-red-600" />}
                </div>
                <div>
                  <p className={`text-xl font-bold ${summary.net_profit >= 0 ? 'text-green-600' : 'text-red-600'}`}>
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
                <div className="p-2 rounded-lg bg-purple-100">
                  <Package className="h-5 w-5 text-purple-600" />
                </div>
                <div>
                  <p className="text-xl font-bold">{summary.orders_delivered}</p>
                  <p className="text-xs text-muted-foreground">Delivered ({summary.orders_returned} returned)</p>
                </div>
              </div>
            </CardContent>
          </Card>
        </div>

        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
          {/* Revenue chart */}
          <Card className="lg:col-span-2">
            <CardHeader><CardTitle className="text-base">Daily Revenue (Last 30 Days)</CardTitle></CardHeader>
            <CardContent>
              {dailyRevenue.length === 0 ? (
                <p className="text-sm text-muted-foreground text-center py-8">No revenue data yet.</p>
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

          {/* P&L breakdown */}
          <Card>
            <CardHeader><CardTitle className="text-base">P&L Breakdown</CardTitle></CardHeader>
            <CardContent className="space-y-3 text-sm">
              <div className="flex justify-between">
                <span>Gross Revenue</span>
                <span className="font-semibold">{formatCurrency(summary.gross_revenue)}</span>
              </div>
              <div className="flex justify-between text-red-600">
                <span>Refunds</span>
                <span>-{formatCurrency(summary.refunds)}</span>
              </div>
              <div className="border-t pt-2 flex justify-between font-medium">
                <span>Net Revenue</span>
                <span>{formatCurrency(summary.net_revenue)}</span>
              </div>
              <div className="flex justify-between text-red-600">
                <span>COGS</span>
                <span>-{formatCurrency(summary.cogs)}</span>
              </div>
              <div className="border-t pt-2 flex justify-between font-medium">
                <span>Gross Profit</span>
                <span>{formatCurrency(summary.gross_profit)}</span>
              </div>
              <div className="flex justify-between text-red-600">
                <span>Shipping</span>
                <span>-{formatCurrency(summary.shipping_costs)}</span>
              </div>
              <div className="flex justify-between text-red-600">
                <span>Commissions</span>
                <span>-{formatCurrency(summary.commissions)}</span>
              </div>
              <div className="border-t pt-2 flex justify-between font-bold text-lg">
                <span>Net Profit</span>
                <span className={summary.net_profit >= 0 ? 'text-green-600' : 'text-red-600'}>
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
                      {commissionStats.pending_count} pending ({formatCurrency(commissionStats.pending_total)})
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
