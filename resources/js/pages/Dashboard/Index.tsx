import { Head, Link } from '@inertiajs/react';
import {
  Truck,
  CheckCircle2,
  XCircle,
  Users,
  TrendingUp,
  TrendingDown,
  Clock,
  AlertCircle,
  QrCode,
  ClipboardCheck,
  Recycle,
  BarChart3,
  ArrowRight,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { DashboardStats, DashboardHourlyItem, DashboardActivity, DashboardTrends } from '@/types';

interface Props {
  stats: DashboardStats;
  recentActivity: DashboardActivity[];
  hourlyActivity: DashboardHourlyItem[];
  trends: DashboardTrends;
}

function StatCard({
  title,
  value,
  description,
  icon: Icon,
  trend,
  variant = 'default',
  href,
}: {
  title: string;
  value: string | number;
  description?: string;
  icon: React.ComponentType<{ className?: string }>;
  trend?: { value: number | null; label: string };
  variant?: 'default' | 'success' | 'warning' | 'danger';
  href?: string;
}) {
  const iconColors = {
    default: 'text-primary',
    success: 'text-green-500',
    warning: 'text-yellow-500',
    danger: 'text-red-500',
  };
  const bgColors = {
    default: 'bg-primary/10',
    success: 'bg-green-500/10',
    warning: 'bg-yellow-500/10',
    danger: 'bg-red-500/10',
  };

  const content = (
    <Card className={`transition-all ${href ? 'hover:shadow-md hover:border-primary/50 cursor-pointer' : ''}`}>
      <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
        <CardTitle className="text-sm font-medium text-muted-foreground">{title}</CardTitle>
        <div className={`rounded-lg p-2 ${bgColors[variant]}`}>
          <Icon className={`h-4 w-4 ${iconColors[variant]}`} />
        </div>
      </CardHeader>
      <CardContent>
        <div className="text-2xl font-bold">{value}</div>
        {description && <p className="text-xs text-muted-foreground mt-1">{description}</p>}
        {trend && trend.value !== null && (
          <div className="flex items-center gap-1 mt-2">
            {trend.value >= 0 ? (
              <TrendingUp className="h-4 w-4 text-green-500" />
            ) : (
              <TrendingDown className="h-4 w-4 text-red-500" />
            )}
            <span className={`text-xs font-medium ${trend.value >= 0 ? 'text-green-500' : 'text-red-500'}`}>
              {trend.value >= 0 ? '+' : ''}{trend.value}%
            </span>
            <span className="text-xs text-muted-foreground">{trend.label}</span>
          </div>
        )}
      </CardContent>
    </Card>
  );

  if (href) return <Link href={href}>{content}</Link>;
  return content;
}

const ACTIVITY_ICONS: Record<string, React.ComponentType<{ className?: string }>> = {
  Waybill: Truck,
  Lead: Users,
  QC: ClipboardCheck,
  System: BarChart3,
};

const ACTIVITY_COLORS: Record<string, string> = {
  Waybill: 'bg-blue-500',
  Lead: 'bg-green-500',
  QC: 'bg-purple-500',
  System: 'bg-gray-500',
};

export default function Dashboard({ stats, recentActivity, hourlyActivity, trends }: Props) {
  const s = stats ?? {
    total_waybills: 0, pending_dispatch: 0, in_transit: 0, delivered_today: 0,
    returned_today: 0, total_leads: 0, new_leads: 0, sales_today: 0,
    conversion_rate: 0, qc_pending: 0, agents_online: 0,
  };

  const chartData = hourlyActivity.length > 0
    ? hourlyActivity
    : Array.from({ length: 12 }, (_, i) => ({ hour: String(8 + i), waybills: 0 }));

  const chartMax = Math.max(...chartData.map((d) => d.waybills), 1);

  const today = new Date();
  const dateLabel = today.toLocaleDateString(undefined, { weekday: 'long', month: 'short', day: 'numeric' });

  return (
    <AppLayout>
      <Head title="Dashboard" />

      <div className="space-y-6">
        {/* Header */}
        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div>
            <h1 className="text-3xl font-bold tracking-tight">Dashboard</h1>
            <p className="text-muted-foreground">Overview of warehouse operations and key metrics</p>
          </div>
          <Button asChild>
            <Link href="/scanner">
              <QrCode className="mr-2 h-4 w-4" />
              Open Scanner
            </Link>
          </Button>
        </div>

        {/* Waybill stats */}
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
          <StatCard
            title="Pending Dispatch"
            value={s.pending_dispatch}
            icon={Clock}
            variant="warning"
            description="Waybills awaiting scan"
            href="/waybills"
          />
          <StatCard
            title="In Transit"
            value={s.in_transit}
            icon={Truck}
            description="With courier"
            href="/waybills"
          />
          <StatCard
            title="Delivered Today"
            value={s.delivered_today}
            icon={CheckCircle2}
            variant="success"
            trend={{ value: trends?.delivered ?? null, label: 'vs yesterday' }}
          />
          <StatCard
            title="Returns Today"
            value={s.returned_today}
            icon={XCircle}
            variant="danger"
          />
        </div>

        {/* Lead / ops stats */}
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
          <StatCard
            title="New Leads"
            value={s.new_leads}
            icon={Users}
            variant="success"
            description="Unassigned"
            href="/leads"
          />
          <StatCard
            title="Sales Today"
            value={s.sales_today}
            icon={TrendingUp}
            variant="success"
            trend={{ value: trends?.sales ?? null, label: 'vs yesterday' }}
          />
          <StatCard
            title="QC Pending"
            value={s.qc_pending}
            icon={AlertCircle}
            variant={s.qc_pending > 10 ? 'danger' : 'warning'}
            description="Awaiting review"
            href="/qc"
          />
          <StatCard
            title="Agents Online"
            value={s.agents_online}
            icon={Users}
            variant="success"
            description="Active in last hour"
            href="/agents/governance"
          />
        </div>

        {/* Chart + Activity */}
        <div className="grid gap-6 lg:grid-cols-3">
          <Card className="lg:col-span-2">
            <CardHeader>
              <div className="flex items-center justify-between">
                <div>
                  <CardTitle>Today's Activity</CardTitle>
                  <CardDescription>Hourly waybill volume</CardDescription>
                </div>
                <Badge variant="outline" className="text-xs">{dateLabel}</Badge>
              </div>
            </CardHeader>
            <CardContent>
              {chartMax === 1 && chartData.every((d) => d.waybills === 0) ? (
                <div className="h-32 flex items-center justify-center text-sm text-muted-foreground">
                  No waybill activity yet today
                </div>
              ) : (
                <div className="flex items-end gap-1.5 h-32">
                  {chartData.map((item) => {
                    const hour = parseInt(item.hour, 10);
                    const isCurrentHour = today.getHours() === hour;
                    const heightPct = Math.max((item.waybills / chartMax) * 100, item.waybills > 0 ? 4 : 1);
                    return (
                      <div key={item.hour} className="flex-1 flex flex-col items-center gap-1 group">
                        <div className="relative w-full">
                          {item.waybills > 0 && (
                            <span className="absolute -top-5 left-1/2 -translate-x-1/2 text-[9px] text-muted-foreground opacity-0 group-hover:opacity-100 transition-opacity whitespace-nowrap">
                              {item.waybills}
                            </span>
                          )}
                          <div
                            className={`w-full rounded-t transition-all ${
                              isCurrentHour ? 'bg-primary' : 'bg-primary/30 hover:bg-primary/60'
                            }`}
                            style={{ height: `${heightPct}%`, minHeight: '4px' }}
                          />
                        </div>
                        <span className="text-[10px] text-muted-foreground">
                          {hour > 12 ? hour - 12 : hour}{hour >= 12 ? 'p' : 'a'}
                        </span>
                      </div>
                    );
                  })}
                </div>
              )}

              <div className="mt-4 grid grid-cols-3 gap-4 pt-4 border-t">
                <div className="text-center">
                  <p className="text-2xl font-bold">{s.total_waybills.toLocaleString()}</p>
                  <p className="text-xs text-muted-foreground">Total Waybills</p>
                </div>
                <div className="text-center">
                  <p className="text-2xl font-bold">{s.total_leads.toLocaleString()}</p>
                  <p className="text-xs text-muted-foreground">Total Leads</p>
                </div>
                <div className="text-center">
                  <p className="text-2xl font-bold text-green-600">{s.conversion_rate}%</p>
                  <p className="text-xs text-muted-foreground">Conversion Rate</p>
                </div>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Recent Activity</CardTitle>
              <CardDescription>Latest system events</CardDescription>
            </CardHeader>
            <CardContent>
              {recentActivity.length === 0 ? (
                <p className="text-sm text-muted-foreground text-center py-8">No recent activity</p>
              ) : (
                <div className="space-y-4">
                  {recentActivity.slice(0, 6).map((activity) => {
                    const Icon = ACTIVITY_ICONS[activity.type] ?? BarChart3;
                    const color = ACTIVITY_COLORS[activity.type] ?? 'bg-gray-500';
                    return (
                      <div key={activity.id} className="flex items-start gap-3">
                        <div className={`rounded-full p-1.5 shrink-0 ${color}`}>
                          <Icon className="h-3 w-3 text-white" />
                        </div>
                        <div className="flex-1 min-w-0">
                          <p className="text-sm truncate">{activity.message}</p>
                          <p className="text-xs text-muted-foreground">{activity.time}</p>
                        </div>
                      </div>
                    );
                  })}
                </div>
              )}
            </CardContent>
          </Card>
        </div>

        {/* Quick Actions */}
        <Card>
          <CardHeader>
            <CardTitle>Quick Actions</CardTitle>
            <CardDescription>Frequently used operations</CardDescription>
          </CardHeader>
          <CardContent>
            <div className="grid gap-3 md:grid-cols-2 lg:grid-cols-4">
              {[
                { href: '/scanner',      label: 'Scanner',   desc: 'Scan waybills',     icon: QrCode,         color: 'bg-primary/10 text-primary' },
                { href: '/leads',        label: 'Leads',     desc: `${s.new_leads} new`, icon: Users,          color: 'bg-green-500/10 text-green-500' },
                { href: '/qc',           label: 'QC Review', desc: `${s.qc_pending} pending`, icon: ClipboardCheck, color: 'bg-purple-500/10 text-purple-500' },
                { href: '/recycling/pool', label: 'Recycling', desc: 'Lead pool',        icon: Recycle,        color: 'bg-yellow-500/10 text-yellow-500' },
              ].map((item) => (
                <Link
                  key={item.href}
                  href={item.href}
                  className="flex items-center justify-between rounded-lg border p-4 hover:bg-accent transition-colors group"
                >
                  <div className="flex items-center gap-3">
                    <div className={`rounded-lg p-2 ${item.color}`}>
                      <item.icon className="h-5 w-5" />
                    </div>
                    <div>
                      <p className="font-medium">{item.label}</p>
                      <p className="text-xs text-muted-foreground">{item.desc}</p>
                    </div>
                  </div>
                  <ArrowRight className="h-4 w-4 text-muted-foreground group-hover:text-primary transition-colors" />
                </Link>
              ))}
            </div>
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
