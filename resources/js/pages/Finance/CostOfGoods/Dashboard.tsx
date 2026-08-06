import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import {
  AlertTriangle,
  ArrowLeft,
  CheckCircle2,
  Clock,
  Package,
  TrendingDown,
  TrendingUp,
} from 'lucide-react';

interface RealtimeStats {
  total_cost: number;
  total_quantity: number;
  avg_unit_cost: number;
  entries_count: number;
  orders_count: number;
  unsynced_count: number;
  unsynced_cost: number;
}

interface TrendPoint {
  date: string;
  cost: number;
  quantity: number;
  orders: number;
  variance: number;
}

interface TopProduct {
  product_id: number;
  sku: string | null;
  name: string | null;
  total_cost: number;
  total_quantity: number;
}

interface VarianceAlert {
  id: number;
  alert_date: string;
  product_id: number;
  sku: string | null;
  name: string | null;
  severity: string;
  alert_type: string;
  actual_cost: number;
  standard_cost: number;
  variance_amount: number;
  variance_pct: number;
  affected_entries: number;
  message: string;
  resolved: boolean;
  resolved_at: string | null;
}

interface DashboardData {
  today: RealtimeStats;
  period: RealtimeStats;
  trend: TrendPoint[];
  top_products: TopProduct[];
  alerts: VarianceAlert[];
  days: number;
}

interface Props {
  dashboard: DashboardData;
  filters: { days?: string; severity?: string; resolved?: string };
}

const SEVERITY_COLOR: Record<string, string> = {
  HIGH: 'text-destructive',
  MEDIUM: 'text-warning',
  LOW: 'text-muted-foreground',
};

const SEVERITY_BG: Record<string, string> = {
  HIGH: 'bg-destructive/10 border-destructive/30',
  MEDIUM: 'bg-warning/10 border-warning/30',
  LOW: 'bg-muted border-muted',
};

export default function CogsDashboard({ dashboard, filters }: Props) {
  const [days, setDays] = useState(filters.days ?? '30');
  const [severity, setSeverity] = useState(filters.severity ?? '');
  const [resolvingId, setResolvingId] = useState<number | null>(null);

  function applyFilters() {
    const params: Record<string, string> = {};
    if (days) params.days = days;
    if (severity) params.severity = severity;
    router.get('/finance/cost-of-goods/dashboard', params, { preserveScroll: true });
  }

  function resolveAlert(alertId: number) {
    setResolvingId(alertId);
    router.patch(
      `/api/cogs/alerts/${alertId}/resolve`,
      {},
      {
        preserveScroll: true,
        onFinish: () => setResolvingId(null),
      }
    );
  }

  const { today, period, trend, top_products, alerts } = dashboard;

  const maxTrendCost = Math.max(...trend.map((t) => t.cost), 1);

  return (
    <>
      <Head title="COGS Dashboard" />
      <div className="space-y-6 p-6">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            <Link
              href="/finance/cost-of-goods"
              className="text-muted-foreground hover:text-foreground"
            >
              <ArrowLeft className="h-5 w-5" />
            </Link>
            <div>
              <h1 className="text-2xl font-bold">COGS Real-Time Dashboard</h1>
              <p className="text-sm text-muted-foreground">
                Auto-calculated on delivery · Daily summaries · Variance alerts
              </p>
            </div>
          </div>
        </div>

        {/* Today's Stats */}
        <div>
          <h2 className="mb-3 text-sm font-semibold text-muted-foreground">Today</h2>
          <div className="grid grid-cols-2 gap-4 sm:grid-cols-4 lg:grid-cols-7">
            <StatCard
              label="COGS Today"
              value={`₱${today.total_cost.toLocaleString('en-PH', { minimumFractionDigits: 2 })}`}
              icon={TrendingDown}
              color="text-destructive"
            />
            <StatCard
              label="Units Sold"
              value={Number(today.total_quantity).toLocaleString()}
              icon={Package}
              color="text-info"
            />
            <StatCard
              label="Avg Unit Cost"
              value={`₱${today.avg_unit_cost.toFixed(4)}`}
              icon={TrendingUp}
              color="text-foreground"
            />
            <StatCard
              label="Orders"
              value={today.orders_count}
              icon={CheckCircle2}
              color="text-success"
            />
            <StatCard
              label="Lot Entries"
              value={today.entries_count}
              icon={Clock}
              color="text-muted-foreground"
            />
            <StatCard
              label="Unsynced"
              value={today.unsynced_count}
              icon={AlertTriangle}
              color={today.unsynced_count > 0 ? 'text-warning' : 'text-muted-foreground'}
            />
            <StatCard
              label="Unsynced Cost"
              value={`₱${today.unsynced_cost.toLocaleString('en-PH', { minimumFractionDigits: 2 })}`}
              icon={AlertTriangle}
              color={today.unsynced_cost > 0 ? 'text-warning' : 'text-muted-foreground'}
            />
          </div>
        </div>

        {/* Period Stats */}
        <div>
          <h2 className="mb-3 text-sm font-semibold text-muted-foreground">
            Last {dashboard.days} Days
          </h2>
          <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <StatCard
              label="Total COGS"
              value={`₱${period.total_cost.toLocaleString('en-PH', { minimumFractionDigits: 2 })}`}
              icon={TrendingDown}
              color="text-destructive"
            />
            <StatCard
              label="Total Units"
              value={Number(period.total_quantity).toLocaleString()}
              icon={Package}
              color="text-info"
            />
            <StatCard
              label="Total Orders"
              value={period.orders_count}
              icon={CheckCircle2}
              color="text-success"
            />
            <StatCard
              label="Avg Unit Cost"
              value={`₱${period.avg_unit_cost.toFixed(4)}`}
              icon={TrendingUp}
              color="text-foreground"
            />
          </div>
        </div>

        {/* Filters */}
        <div className="flex items-end gap-3 rounded-lg border p-4">
          <div>
            <label className="mb-1 block text-xs font-medium text-muted-foreground">
              Period (days)
            </label>
            <select
              className="rounded-md border bg-background px-3 py-1.5 text-sm"
              value={days}
              onChange={(e) => setDays(e.target.value)}
            >
              <option value="7">7 days</option>
              <option value="14">14 days</option>
              <option value="30">30 days</option>
              <option value="60">60 days</option>
              <option value="90">90 days</option>
            </select>
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-muted-foreground">
              Alert Severity
            </label>
            <select
              className="rounded-md border bg-background px-3 py-1.5 text-sm"
              value={severity}
              onChange={(e) => setSeverity(e.target.value)}
            >
              <option value="">All</option>
              <option value="HIGH">High</option>
              <option value="MEDIUM">Medium</option>
              <option value="LOW">Low</option>
            </select>
          </div>
          <button className="rounded-md border px-3 py-1.5 text-sm" onClick={applyFilters}>
            Apply
          </button>
        </div>

        {/* Daily Trend Chart */}
        <div className="rounded-lg border">
          <div className="border-b p-4">
            <h2 className="text-lg font-semibold">Daily COGS Trend</h2>
          </div>
          <div className="p-4">
            {trend.length === 0 ? (
              <p className="py-8 text-center text-sm text-muted-foreground">
                No daily summaries yet. Run{' '}
                <code className="rounded bg-muted px-1 py-0.5 text-xs">
                  php artisan cogs:generate-daily-summary
                </code>{' '}
                to generate.
              </p>
            ) : (
              <div className="flex items-end gap-1" style={{ height: '200px' }}>
                {trend.map((point) => {
                  const heightPct = (point.cost / maxTrendCost) * 100;
                  const hasVariance = Math.abs(point.variance) > 0;
                  return (
                    <div
                      key={point.date}
                      className="group relative flex flex-1 flex-col items-center justify-end"
                      style={{ minWidth: '20px' }}
                    >
                      <div
                        className={`w-full rounded-t transition-all hover:opacity-80 ${hasVariance ? 'bg-warning' : 'bg-primary'}`}
                        style={{ height: `${Math.max(heightPct, 2)}%` }}
                        title={`${point.date}: ₱${point.cost.toLocaleString()} (${point.orders} orders)`}
                      />
                      <div className="pointer-events-none absolute -top-16 z-10 hidden rounded-md border bg-popover p-2 text-xs shadow-md group-hover:block">
                        <p className="font-medium">{point.date}</p>
                        <p>
                          COGS: ₱{point.cost.toLocaleString('en-PH', { minimumFractionDigits: 2 })}
                        </p>
                        <p>Units: {Number(point.quantity).toLocaleString()}</p>
                        <p>Orders: {point.orders}</p>
                        {hasVariance && (
                          <p className={point.variance > 0 ? 'text-destructive' : 'text-success'}>
                            Variance: ₱
                            {point.variance.toLocaleString('en-PH', { minimumFractionDigits: 2 })}
                          </p>
                        )}
                      </div>
                    </div>
                  );
                })}
              </div>
            )}
          </div>
        </div>

        {/* Two-column: Top Products + Variance Alerts */}
        <div className="grid gap-4 lg:grid-cols-2">
          {/* Top Products */}
          <div className="rounded-lg border">
            <div className="border-b p-4">
              <h2 className="text-lg font-semibold">Top Products by COGS</h2>
            </div>
            {top_products.length === 0 ? (
              <p className="p-8 text-center text-sm text-muted-foreground">
                No data for this period.
              </p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead className="border-b bg-muted/50">
                    <tr>
                      <th className="px-4 py-2 text-left font-medium">Product</th>
                      <th className="px-4 py-2 text-right font-medium">Qty</th>
                      <th className="px-4 py-2 text-right font-medium">COGS</th>
                    </tr>
                  </thead>
                  <tbody>
                    {top_products.map((p) => (
                      <tr key={p.product_id} className="border-b last:border-0">
                        <td className="px-4 py-2">
                          {p.sku && <span className="font-mono text-xs">{p.sku}</span>}
                          <p className="text-xs text-muted-foreground">{p.name ?? '—'}</p>
                        </td>
                        <td className="px-4 py-2 text-right">
                          {Number(p.total_quantity).toLocaleString()}
                        </td>
                        <td className="px-4 py-2 text-right font-medium">
                          ₱
                          {Number(p.total_cost).toLocaleString('en-PH', {
                            minimumFractionDigits: 2,
                          })}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>

          {/* Variance Alerts */}
          <div className="rounded-lg border">
            <div className="border-b p-4">
              <h2 className="flex items-center gap-2 text-lg font-semibold">
                <AlertTriangle className="h-5 w-5 text-warning" />
                Variance Alerts
                {alerts.length > 0 && (
                  <span className="rounded-full bg-warning/10 px-2 py-0.5 text-xs text-warning">
                    {alerts.length}
                  </span>
                )}
              </h2>
            </div>
            {alerts.length === 0 ? (
              <div className="p-8 text-center">
                <CheckCircle2 className="mx-auto mb-2 h-8 w-8 text-success" />
                <p className="text-sm text-muted-foreground">No active variance alerts.</p>
              </div>
            ) : (
              <div className="max-h-[400px] space-y-2 overflow-y-auto p-3">
                {alerts.map((alert) => (
                  <div
                    key={alert.id}
                    className={`rounded-md border p-3 ${SEVERITY_BG[alert.severity] ?? SEVERITY_BG.LOW}`}
                  >
                    <div className="flex items-start justify-between gap-2">
                      <div className="flex-1">
                        <div className="flex items-center gap-2">
                          <span
                            className={`text-xs font-bold uppercase ${SEVERITY_COLOR[alert.severity]}`}
                          >
                            {alert.severity}
                          </span>
                          <span className="text-xs text-muted-foreground">{alert.alert_date}</span>
                          {alert.sku && <span className="font-mono text-xs">{alert.sku}</span>}
                        </div>
                        <p className="mt-1 text-sm">{alert.message}</p>
                        <div className="mt-1 flex gap-4 text-xs text-muted-foreground">
                          <span>Actual: ₱{alert.actual_cost.toFixed(4)}</span>
                          <span>Standard: ₱{alert.standard_cost.toFixed(4)}</span>
                          <span
                            className={
                              alert.variance_amount > 0 ? 'text-destructive' : 'text-success'
                            }
                          >
                            Var: ₱
                            {alert.variance_amount.toLocaleString('en-PH', {
                              minimumFractionDigits: 2,
                            })}
                          </span>
                        </div>
                      </div>
                      {!alert.resolved && (
                        <button
                          className="shrink-0 rounded-md border px-2 py-1 text-xs hover:bg-background disabled:opacity-50"
                          disabled={resolvingId === alert.id}
                          onClick={() => resolveAlert(alert.id)}
                        >
                          Resolve
                        </button>
                      )}
                      {alert.resolved && (
                        <span className="shrink-0 text-xs text-success">Resolved</span>
                      )}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>
      </div>
    </>
  );
}

function StatCard({
  label,
  value,
  icon: Icon,
  color,
}: {
  label: string;
  value: string | number;
  icon: typeof TrendingDown;
  color: string;
}) {
  return (
    <div className="rounded-lg border p-3">
      <div className="flex items-center gap-2">
        <Icon className={`h-4 w-4 ${color}`} />
        <p className="text-xs font-medium text-muted-foreground">{label}</p>
      </div>
      <p className={`mt-1 text-lg font-bold ${color}`}>{value}</p>
    </div>
  );
}
