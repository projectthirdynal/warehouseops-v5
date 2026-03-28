import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
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
  BarChart3,
  ArrowRight,
  Loader2,
  RefreshCw,
} from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { api } from '@/lib/api';
import type { DashboardData } from '@/types';

function StatCard({
  title,
  value,
  description,
  icon: Icon,
  trend,
  variant = 'default',
  onClick,
}: {
  title: string;
  value: string | number;
  description?: string;
  icon: React.ComponentType<{ className?: string }>;
  trend?: { value: number; label: string };
  variant?: 'default' | 'success' | 'warning' | 'danger';
  onClick?: () => void;
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

  return (
    <Card
      className={`transition-all ${onClick ? 'hover:shadow-md hover:border-primary/50 cursor-pointer' : ''}`}
      onClick={onClick}
    >
      <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
        <CardTitle className="text-sm font-medium text-muted-foreground">{title}</CardTitle>
        <div className={`rounded-lg p-2 ${bgColors[variant]}`}>
          <Icon className={`h-4 w-4 ${iconColors[variant]}`} />
        </div>
      </CardHeader>
      <CardContent>
        <div className="text-2xl font-bold">{value}</div>
        {description && <p className="text-xs text-muted-foreground mt-1">{description}</p>}
        {trend && (
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
}

export default function Dashboard() {
  const [data, setData] = useState<DashboardData | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const navigate = useNavigate();

  const fetchData = async (showRefresh = false) => {
    if (showRefresh) setRefreshing(true);
    try {
      const res = await api.getDashboard();
      setData(res);
    } catch {
      // handled by interceptor
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  };

  useEffect(() => {
    fetchData();
    const interval = setInterval(() => fetchData(), 30000); // Auto-refresh every 30s
    return () => clearInterval(interval);
  }, []);

  if (loading) {
    return (
      <div className="flex items-center justify-center h-[60vh]">
        <Loader2 className="h-8 w-8 animate-spin text-primary" />
      </div>
    );
  }

  const s = data?.stats || {
    pending_dispatch: 0, in_transit: 0, delivered_today: 0, returned_today: 0,
    new_leads: 0, sales_today: 0, qc_pending: 0, agents_online: 0,
  };

  const hourlyData = data?.hourly_activity || [];

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div>
          <h1 className="text-3xl font-bold tracking-tight">Dashboard</h1>
          <p className="text-muted-foreground">Overview of warehouse operations and key metrics</p>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" size="sm" onClick={() => fetchData(true)} disabled={refreshing}>
            <RefreshCw className={`mr-2 h-4 w-4 ${refreshing ? 'animate-spin' : ''}`} />
            Refresh
          </Button>
          <Button onClick={() => navigate('/scanner')}>
            <QrCode className="mr-2 h-4 w-4" />
            Open Scanner
          </Button>
        </div>
      </div>

      {/* Main Stats */}
      <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
        <StatCard title="Pending Dispatch" value={s.pending_dispatch} icon={Clock} variant="warning" description="Waybills awaiting scan" />
        <StatCard title="In Transit" value={s.in_transit} icon={Truck} description="With courier" />
        <StatCard title="Delivered Today" value={s.delivered_today} icon={CheckCircle2} variant="success" trend={{ value: 12, label: 'vs yesterday' }} />
        <StatCard title="Returns Today" value={s.returned_today} icon={XCircle} variant="danger" />
      </div>

      {/* Secondary Stats */}
      <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
        <StatCard title="New Leads" value={s.new_leads} icon={Users} variant="success" description="Unassigned" />
        <StatCard title="Sales Today" value={s.sales_today} icon={TrendingUp} variant="success" trend={{ value: 8, label: 'vs yesterday' }} />
        <StatCard title="QC Pending" value={s.qc_pending} icon={AlertCircle} variant={s.qc_pending > 10 ? 'danger' : 'warning'} description="Awaiting review" />
        <StatCard title="Agents Online" value={s.agents_online} icon={Users} variant="success" description="Currently active" />
      </div>

      {/* Charts and Activity */}
      <div className="grid gap-6 lg:grid-cols-3">
        {/* Today's Activity Chart */}
        <Card className="lg:col-span-2">
          <CardHeader>
            <div className="flex items-center justify-between">
              <div>
                <CardTitle>Today's Activity</CardTitle>
                <CardDescription>Hourly waybill processing</CardDescription>
              </div>
              <Badge variant="outline" className="text-xs">
                {new Date().toLocaleDateString('en-PH', { weekday: 'long', month: 'short', day: 'numeric' })}
              </Badge>
            </div>
          </CardHeader>
          <CardContent>
            <div className="flex items-end gap-2 h-32">
              {(hourlyData.length > 0 ? hourlyData : Array.from({ length: 12 }, (_, i) => ({ hour: `${8 + i}`, waybills: 0, leads: 0 }))).map((item, i) => {
                const hour = parseInt(item.hour) || (8 + i);
                const isCurrentHour = new Date().getHours() === hour;
                const value = item.waybills || 0;
                return (
                  <div key={i} className="flex-1 flex flex-col items-center gap-1">
                    <div
                      className={`w-full rounded-t transition-all ${isCurrentHour ? 'bg-primary' : 'bg-primary/30 hover:bg-primary/50'}`}
                      style={{ height: `${Math.max((value / 50) * 100, 4)}%`, minHeight: '4px' }}
                    />
                    <span className="text-[10px] text-muted-foreground">
                      {hour > 12 ? hour - 12 : hour}{hour >= 12 ? 'p' : 'a'}
                    </span>
                  </div>
                );
              })}
            </div>
          </CardContent>
        </Card>

        {/* Recent Activity */}
        <Card>
          <CardHeader>
            <CardTitle>Recent Activity</CardTitle>
            <CardDescription>Latest system events</CardDescription>
          </CardHeader>
          <CardContent>
            <div className="space-y-4">
              {(data?.recent_activity || []).length === 0 ? (
                <p className="text-sm text-muted-foreground text-center py-8">No recent activity</p>
              ) : (
                data!.recent_activity.slice(0, 6).map((activity) => (
                  <div key={activity.id} className="flex items-start gap-3">
                    <div className="rounded-full p-1.5 bg-blue-500">
                      <BarChart3 className="h-3 w-3 text-white" />
                    </div>
                    <div className="flex-1 min-w-0">
                      <p className="text-sm truncate">{activity.description}</p>
                      <p className="text-xs text-muted-foreground">{activity.time}</p>
                    </div>
                  </div>
                ))
              )}
            </div>
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
              { path: '/scanner', label: 'Scanner', desc: 'Scan waybills', icon: QrCode, color: 'bg-primary/10 text-primary' },
              { path: '/import', label: 'Import', desc: 'Upload files', icon: BarChart3, color: 'bg-green-500/10 text-green-500' },
              { path: '/monitoring', label: 'Monitoring', desc: 'Live analytics', icon: BarChart3, color: 'bg-purple-500/10 text-purple-500' },
            ].map((item) => (
              <button
                key={item.path}
                onClick={() => navigate(item.path)}
                className="flex items-center justify-between rounded-lg border p-4 hover:bg-accent transition-colors group text-left"
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
              </button>
            ))}
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
