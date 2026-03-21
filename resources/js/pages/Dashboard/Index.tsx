import { Head } from '@inertiajs/react';
import {
  Package,
  Truck,
  CheckCircle2,
  XCircle,
  Users,
  TrendingUp,
  Clock,
  AlertCircle,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import type { DashboardStats } from '@/types';

interface Props {
  stats: DashboardStats;
  recentActivity: Array<{
    id: number;
    type: string;
    message: string;
    time: string;
  }>;
}

function StatCard({
  title,
  value,
  description,
  icon: Icon,
  trend,
  variant = 'default',
}: {
  title: string;
  value: string | number;
  description?: string;
  icon: React.ComponentType<{ className?: string }>;
  trend?: { value: number; label: string };
  variant?: 'default' | 'success' | 'warning' | 'danger';
}) {
  const iconColors = {
    default: 'text-primary',
    success: 'text-green-500',
    warning: 'text-yellow-500',
    danger: 'text-red-500',
  };

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
        <CardTitle className="text-sm font-medium text-muted-foreground">
          {title}
        </CardTitle>
        <Icon className={`h-5 w-5 ${iconColors[variant]}`} />
      </CardHeader>
      <CardContent>
        <div className="text-2xl font-bold">{value}</div>
        {description && (
          <p className="text-xs text-muted-foreground mt-1">{description}</p>
        )}
        {trend && (
          <div className="flex items-center gap-1 mt-2">
            <TrendingUp
              className={`h-4 w-4 ${trend.value >= 0 ? 'text-green-500' : 'text-red-500'}`}
            />
            <span
              className={`text-xs font-medium ${trend.value >= 0 ? 'text-green-500' : 'text-red-500'}`}
            >
              {trend.value >= 0 ? '+' : ''}
              {trend.value}%
            </span>
            <span className="text-xs text-muted-foreground">{trend.label}</span>
          </div>
        )}
      </CardContent>
    </Card>
  );
}

export default function Dashboard({ stats, recentActivity }: Props) {
  // Default stats if not provided
  const defaultStats: DashboardStats = {
    total_waybills: 0,
    pending_dispatch: 0,
    in_transit: 0,
    delivered_today: 0,
    returned_today: 0,
    total_leads: 0,
    new_leads: 0,
    sales_today: 0,
    conversion_rate: 0,
    qc_pending: 0,
    agents_online: 0,
  };

  const s = stats || defaultStats;

  return (
    <AppLayout>
      <Head title="Dashboard" />

      <div className="space-y-6">
        {/* Page Header */}
        <div>
          <h1 className="text-3xl font-bold tracking-tight">Dashboard</h1>
          <p className="text-muted-foreground">
            Overview of warehouse operations and key metrics
          </p>
        </div>

        {/* Waybill Stats */}
        <div>
          <h2 className="text-lg font-semibold mb-4">Waybill Overview</h2>
          <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
            <StatCard
              title="Total Waybills"
              value={s.total_waybills.toLocaleString()}
              icon={Package}
              description="All time"
            />
            <StatCard
              title="Pending Dispatch"
              value={s.pending_dispatch}
              icon={Clock}
              variant="warning"
              description="Awaiting scan"
            />
            <StatCard
              title="In Transit"
              value={s.in_transit}
              icon={Truck}
              description="With courier"
            />
            <StatCard
              title="Delivered Today"
              value={s.delivered_today}
              icon={CheckCircle2}
              variant="success"
              trend={{ value: 12, label: 'vs yesterday' }}
            />
          </div>
        </div>

        {/* Lead Stats */}
        <div>
          <h2 className="text-lg font-semibold mb-4">Lead Management</h2>
          <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
            <StatCard
              title="Total Leads"
              value={s.total_leads.toLocaleString()}
              icon={Users}
            />
            <StatCard
              title="New Leads"
              value={s.new_leads}
              icon={Users}
              variant="success"
              description="Unassigned"
            />
            <StatCard
              title="Sales Today"
              value={s.sales_today}
              icon={TrendingUp}
              variant="success"
              trend={{ value: 8, label: 'vs yesterday' }}
            />
            <StatCard
              title="Conversion Rate"
              value={`${s.conversion_rate}%`}
              icon={TrendingUp}
              description="This month"
            />
          </div>
        </div>

        {/* Operations Stats */}
        <div>
          <h2 className="text-lg font-semibold mb-4">Operations</h2>
          <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
            <StatCard
              title="QC Pending"
              value={s.qc_pending}
              icon={AlertCircle}
              variant={s.qc_pending > 10 ? 'danger' : 'warning'}
              description="Awaiting review"
            />
            <StatCard
              title="Returns Today"
              value={s.returned_today}
              icon={XCircle}
              variant="danger"
            />
            <StatCard
              title="Agents Online"
              value={s.agents_online}
              icon={Users}
              variant="success"
              description="Currently active"
            />
            <StatCard
              title="Avg Processing"
              value="2.4h"
              icon={Clock}
              description="Lead to waybill"
            />
          </div>
        </div>

        {/* Recent Activity */}
        <div className="grid gap-4 lg:grid-cols-2">
          <Card>
            <CardHeader>
              <CardTitle>Recent Activity</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="space-y-4">
                {(recentActivity || []).length === 0 ? (
                  <p className="text-sm text-muted-foreground">
                    No recent activity
                  </p>
                ) : (
                  recentActivity.map((activity) => (
                    <div
                      key={activity.id}
                      className="flex items-center gap-4 text-sm"
                    >
                      <div className="flex-1">
                        <p>{activity.message}</p>
                        <p className="text-xs text-muted-foreground">
                          {activity.time}
                        </p>
                      </div>
                      <Badge variant="secondary">{activity.type}</Badge>
                    </div>
                  ))
                )}
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Quick Actions</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="grid gap-2">
                <a
                  href="/scanner"
                  className="flex items-center gap-3 rounded-lg border p-3 hover:bg-accent transition-colors"
                >
                  <Package className="h-5 w-5 text-primary" />
                  <div>
                    <p className="font-medium">Open Scanner</p>
                    <p className="text-xs text-muted-foreground">
                      Scan waybills for dispatch
                    </p>
                  </div>
                </a>
                <a
                  href="/leads"
                  className="flex items-center gap-3 rounded-lg border p-3 hover:bg-accent transition-colors"
                >
                  <Users className="h-5 w-5 text-primary" />
                  <div>
                    <p className="font-medium">Manage Leads</p>
                    <p className="text-xs text-muted-foreground">
                      View and assign leads
                    </p>
                  </div>
                </a>
                <a
                  href="/qc"
                  className="flex items-center gap-3 rounded-lg border p-3 hover:bg-accent transition-colors"
                >
                  <CheckCircle2 className="h-5 w-5 text-primary" />
                  <div>
                    <p className="font-medium">QC Review</p>
                    <p className="text-xs text-muted-foreground">
                      Review pending sales
                    </p>
                  </div>
                </a>
              </div>
            </CardContent>
          </Card>
        </div>
      </div>
    </AppLayout>
  );
}
