import { Head } from '@inertiajs/react';
import { TrendingUp, DollarSign, ShoppingCart, ArrowUpRight, ArrowDownRight } from 'lucide-react';
import {
  LineChart,
  Line,
  BarChart,
  Bar,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
  PieChart,
  Pie,
  Cell,
} from 'recharts';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';

interface Props {
  orderCounts: Record<string, any>;
  revenueTotals: Record<string, any>;
  statusBreakdown: Record<string, any>;
  topProducts: Record<string, any>;
  salesTrends: Record<string, any>;
  revenueBySource: Record<string, any>;
  revenueByPaymentMethod: Record<string, any>;
  agentLeaderboard: Record<string, any>;
  cohortRetention: Record<string, any>;
  averageOrderValue: Record<string, any>;
  returnRefundRate: Record<string, any>;
  predictiveInsights: Record<string, any> | null;
  widgetConfig: Record<string, any> | null;
}

const COLORS = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899'];
const fmtCurrency = (v: number) =>
  new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  }).format(v);
const fmtNum = (v: number) => new Intl.NumberFormat('en-PH').format(v);

function TrendBadge({ trend }: { trend: number | null }) {
  if (trend === null) return <span className="text-xs text-muted-foreground">—</span>;
  const pos = trend >= 0;
  return (
    <span
      className={`inline-flex items-center gap-0.5 text-xs font-medium ${pos ? 'text-success' : 'text-destructive'}`}
    >
      {pos ? <ArrowUpRight className="h-3 w-3" /> : <ArrowDownRight className="h-3 w-3" />}
      {Math.abs(trend)}%
    </span>
  );
}

function StatCard({
  title,
  value,
  sub,
  icon: Icon,
  trend,
}: {
  title: string;
  value: string | number;
  sub?: string;
  icon: React.ComponentType<{ className?: string }>;
  trend?: number | null;
}) {
  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
        <CardTitle className="text-sm font-medium text-muted-foreground">{title}</CardTitle>
        <div className="rounded-lg bg-primary/10 p-2">
          <Icon className="h-4 w-4 text-primary" />
        </div>
      </CardHeader>
      <CardContent>
        <div className="text-2xl font-bold font-display">{value}</div>
        <div className="flex items-center gap-2 mt-1">
          {sub && <p className="text-xs text-muted-foreground">{sub}</p>}
          {trend !== undefined && <TrendBadge trend={trend} />}
        </div>
      </CardContent>
    </Card>
  );
}

export default function SalesDashboardIndex(props: Props) {
  const {
    orderCounts: oc,
    revenueTotals: rv,
    statusBreakdown: sb,
    topProducts: tp,
    salesTrends: st,
    agentLeaderboard: al,
  } = props;

  return (
    <AppLayout>
      <Head title="Sales Dashboard" />
      <div className="space-y-6 p-6">
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
          <StatCard
            title="Orders Today"
            value={fmtNum(oc?.today ?? 0)}
            sub={`Total: ${fmtNum(oc?.total ?? 0)}`}
            icon={ShoppingCart}
            trend={oc?.today_trend ?? null}
          />
          <StatCard
            title="Revenue Today"
            value={fmtCurrency(rv?.today_gross ?? 0)}
            sub={`Net: ${fmtCurrency(rv?.today_net ?? 0)}`}
            icon={DollarSign}
            trend={rv?.today_trend ?? null}
          />
          <StatCard
            title="Orders This Week"
            value={fmtNum(oc?.this_week ?? 0)}
            icon={TrendingUp}
            trend={oc?.week_trend ?? null}
          />
          <StatCard
            title="Revenue This Month"
            value={fmtCurrency(rv?.this_month_gross ?? 0)}
            sub={`Net: ${fmtCurrency(rv?.this_month_net ?? 0)}`}
            icon={DollarSign}
            trend={rv?.month_trend ?? null}
          />
        </div>

        <Card>
          <CardHeader>
            <CardTitle>Sales Trends — {st?.period ?? 'daily'}</CardTitle>
          </CardHeader>
          <CardContent>
            <ResponsiveContainer width="100%" height={300}>
              <LineChart data={st?.chart_data ?? []}>
                <CartesianGrid strokeDasharray="3 3" className="opacity-30" />
                <XAxis dataKey="label" fontSize={12} />
                <YAxis fontSize={12} />
                <Tooltip />
                <Line
                  type="monotone"
                  dataKey="orders"
                  stroke="#3b82f6"
                  strokeWidth={2}
                  dot={false}
                />
                <Line
                  type="monotone"
                  dataKey="revenue"
                  stroke="#10b981"
                  strokeWidth={2}
                  dot={false}
                />
              </LineChart>
            </ResponsiveContainer>
          </CardContent>
        </Card>

        <div className="grid gap-6 lg:grid-cols-2">
          <Card>
            <CardHeader>
              <CardTitle>Revenue (Last 30 Days)</CardTitle>
            </CardHeader>
            <CardContent>
              <ResponsiveContainer width="100%" height={250}>
                <BarChart data={rv?.daily ?? []}>
                  <CartesianGrid strokeDasharray="3 3" className="opacity-30" />
                  <XAxis dataKey="label" fontSize={10} />
                  <YAxis fontSize={10} />
                  <Tooltip formatter={(v: number) => fmtCurrency(v)} />
                  <Bar dataKey="net" fill="#10b981" />
                </BarChart>
              </ResponsiveContainer>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Order Status Breakdown</CardTitle>
            </CardHeader>
            <CardContent>
              <ResponsiveContainer width="100%" height={250}>
                <PieChart>
                  <Pie
                    data={sb?.statuses ?? []}
                    dataKey="count"
                    nameKey="label"
                    cx="50%"
                    cy="50%"
                    outerRadius={80}
                    label={(e: any) => e.label}
                  >
                    {(sb?.statuses ?? []).map((_: any, i: number) => (
                      <Cell key={i} fill={COLORS[i % COLORS.length]} />
                    ))}
                  </Pie>
                  <Tooltip />
                </PieChart>
              </ResponsiveContainer>
            </CardContent>
          </Card>
        </div>

        <div className="grid gap-6 lg:grid-cols-2">
          <Card>
            <CardHeader>
              <CardTitle>Top Products</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="space-y-2">
                {(tp?.products ?? []).map((p: any, i: number) => (
                  <div key={i} className="flex items-center justify-between text-sm">
                    <span className="font-medium">{p.product_name}</span>
                    <div className="flex items-center gap-3">
                      <Badge variant="secondary">{p.quantity} sold</Badge>
                      <span className="text-muted-foreground">{fmtCurrency(p.revenue)}</span>
                    </div>
                  </div>
                ))}
                {(!tp?.products || tp.products.length === 0) && (
                  <p className="text-sm text-muted-foreground">No data available.</p>
                )}
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Agent Leaderboard</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="space-y-2">
                {(al?.agents ?? []).map((a: any, i: number) => (
                  <div key={i} className="flex items-center justify-between text-sm">
                    <div className="flex items-center gap-2">
                      <Badge variant="outline">#{a.rank}</Badge>
                      <span className="font-medium">{a.agent_name}</span>
                    </div>
                    <div className="flex items-center gap-3">
                      <Badge variant="secondary">{a.order_count} orders</Badge>
                      <span className="text-muted-foreground">{fmtCurrency(a.revenue)}</span>
                    </div>
                  </div>
                ))}
                {(!al?.agents || al.agents.length === 0) && (
                  <p className="text-sm text-muted-foreground">No data available.</p>
                )}
              </div>
            </CardContent>
          </Card>
        </div>
      </div>
    </AppLayout>
  );
}
