import { Head } from '@inertiajs/react';
import {
  TrendingUp,
  TrendingDown,
  Users,
  Truck,
  Target,
  Activity,
  Calendar,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';

interface Props {
  metrics: {
    leads: {
      total: number;
      new_today: number;
      converted: number;
      conversion_rate: number;
      trend: number;
    };
    waybills: {
      total: number;
      dispatched_today: number;
      delivered_today: number;
      returned_today: number;
      delivery_rate: number;
    };
    agents: {
      total: number;
      online: number;
      avg_performance: number;
      top_performer: string;
    };
    revenue: {
      today: number;
      this_week: number;
      this_month: number;
      trend: number;
    };
  };
  hourly_data: { hour: string; leads: number; sales: number }[];
  agent_performance: { name: string; leads: number; sales: number; rate: number }[];
}

export default function MonitoringIndex({ metrics, hourly_data, agent_performance }: Props) {
  return (
    <AppLayout>
      <Head title="Monitoring" />

      <div className="space-y-6">
        {/* Header */}
        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div>
            <h1 className="text-2xl font-bold tracking-tight">Monitoring Dashboard</h1>
            <p className="text-muted-foreground">
              Real-time analytics and performance tracking
            </p>
          </div>
          <div className="flex gap-2">
            <Select defaultValue="today">
              <SelectTrigger className="w-[140px]">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="today">Today</SelectItem>
                <SelectItem value="yesterday">Yesterday</SelectItem>
                <SelectItem value="week">This Week</SelectItem>
                <SelectItem value="month">This Month</SelectItem>
              </SelectContent>
            </Select>
            <Button variant="outline">
              <Calendar className="mr-2 h-4 w-4" />
              Custom Range
            </Button>
          </div>
        </div>

        {/* KPI Cards */}
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">Leads Today</CardTitle>
              <Users className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{metrics?.leads?.new_today || 0}</div>
              <div className="flex items-center text-sm">
                {(metrics?.leads?.trend || 0) >= 0 ? (
                  <TrendingUp className="mr-1 h-4 w-4 text-green-600" />
                ) : (
                  <TrendingDown className="mr-1 h-4 w-4 text-red-600" />
                )}
                <span className={(metrics?.leads?.trend || 0) >= 0 ? 'text-green-600' : 'text-red-600'}>
                  {Math.abs(metrics?.leads?.trend || 0)}%
                </span>
                <span className="ml-1 text-muted-foreground">vs yesterday</span>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">Conversion Rate</CardTitle>
              <Target className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{metrics?.leads?.conversion_rate || 0}%</div>
              <div className="text-sm text-muted-foreground">
                {metrics?.leads?.converted || 0} of {metrics?.leads?.total || 0} leads
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">Deliveries Today</CardTitle>
              <Truck className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{metrics?.waybills?.delivered_today || 0}</div>
              <div className="text-sm text-muted-foreground">
                {metrics?.waybills?.delivery_rate || 0}% delivery rate
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">Revenue Today</CardTitle>
              <Activity className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">₱{(metrics?.revenue?.today || 0).toLocaleString()}</div>
              <div className="flex items-center text-sm">
                {(metrics?.revenue?.trend || 0) >= 0 ? (
                  <TrendingUp className="mr-1 h-4 w-4 text-green-600" />
                ) : (
                  <TrendingDown className="mr-1 h-4 w-4 text-red-600" />
                )}
                <span className={(metrics?.revenue?.trend || 0) >= 0 ? 'text-green-600' : 'text-red-600'}>
                  {Math.abs(metrics?.revenue?.trend || 0)}%
                </span>
              </div>
            </CardContent>
          </Card>
        </div>

        {/* Charts Row */}
        <div className="grid gap-6 lg:grid-cols-2">
          {/* Hourly Activity */}
          <Card>
            <CardHeader>
              <CardTitle>Hourly Activity</CardTitle>
              <CardDescription>Leads and sales throughout the day</CardDescription>
            </CardHeader>
            <CardContent>
              <div className="h-[300px] flex items-end justify-between gap-2">
                {(hourly_data || [
                  { hour: '8AM', leads: 12, sales: 3 },
                  { hour: '9AM', leads: 25, sales: 8 },
                  { hour: '10AM', leads: 38, sales: 12 },
                  { hour: '11AM', leads: 45, sales: 15 },
                  { hour: '12PM', leads: 30, sales: 10 },
                  { hour: '1PM', leads: 35, sales: 11 },
                  { hour: '2PM', leads: 42, sales: 14 },
                  { hour: '3PM', leads: 48, sales: 16 },
                  { hour: '4PM', leads: 40, sales: 13 },
                  { hour: '5PM', leads: 28, sales: 9 },
                ]).map((item, i) => (
                  <div key={i} className="flex-1 flex flex-col items-center gap-1">
                    <div className="w-full flex flex-col gap-1">
                      <div
                        className="w-full bg-primary/20 rounded-t"
                        style={{ height: `${(item.leads / 50) * 200}px` }}
                      />
                      <div
                        className="w-full bg-primary rounded-t"
                        style={{ height: `${(item.sales / 20) * 80}px` }}
                      />
                    </div>
                    <span className="text-xs text-muted-foreground">{item.hour}</span>
                  </div>
                ))}
              </div>
              <div className="mt-4 flex items-center justify-center gap-6">
                <div className="flex items-center gap-2">
                  <div className="h-3 w-3 rounded bg-primary/20" />
                  <span className="text-sm text-muted-foreground">Leads</span>
                </div>
                <div className="flex items-center gap-2">
                  <div className="h-3 w-3 rounded bg-primary" />
                  <span className="text-sm text-muted-foreground">Sales</span>
                </div>
              </div>
            </CardContent>
          </Card>

          {/* Agent Performance */}
          <Card>
            <CardHeader>
              <CardTitle>Agent Performance</CardTitle>
              <CardDescription>Top performing agents today</CardDescription>
            </CardHeader>
            <CardContent>
              <div className="space-y-4">
                {(agent_performance || [
                  { name: 'Maria Santos', leads: 45, sales: 18, rate: 40 },
                  { name: 'Juan dela Cruz', leads: 38, sales: 14, rate: 37 },
                  { name: 'Ana Reyes', leads: 42, sales: 15, rate: 36 },
                  { name: 'Pedro Garcia', leads: 35, sales: 12, rate: 34 },
                  { name: 'Sofia Cruz', leads: 30, sales: 10, rate: 33 },
                ]).map((agent, i) => (
                  <div key={i} className="flex items-center gap-4">
                    <div className="flex h-10 w-10 items-center justify-center rounded-full bg-primary/10 text-primary font-semibold">
                      {i + 1}
                    </div>
                    <div className="flex-1">
                      <div className="flex items-center justify-between">
                        <span className="font-medium">{agent.name}</span>
                        <Badge variant={i === 0 ? 'default' : 'secondary'}>
                          {agent.rate}%
                        </Badge>
                      </div>
                      <div className="mt-1 flex items-center gap-4 text-sm text-muted-foreground">
                        <span>{agent.leads} leads</span>
                        <span>{agent.sales} sales</span>
                      </div>
                      <div className="mt-1 h-2 rounded-full bg-muted">
                        <div
                          className="h-full rounded-full bg-primary"
                          style={{ width: `${agent.rate}%` }}
                        />
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>
        </div>

        {/* Operations Overview */}
        <div className="grid gap-6 lg:grid-cols-3">
          <Card>
            <CardHeader>
              <CardTitle className="text-sm">Waybill Status</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
              <div className="flex items-center justify-between">
                <span className="text-muted-foreground">Pending</span>
                <span className="font-medium">{metrics?.waybills?.total || 0}</span>
              </div>
              <div className="flex items-center justify-between">
                <span className="text-muted-foreground">Dispatched</span>
                <span className="font-medium">{metrics?.waybills?.dispatched_today || 0}</span>
              </div>
              <div className="flex items-center justify-between">
                <span className="text-muted-foreground">Delivered</span>
                <span className="font-medium text-green-600">{metrics?.waybills?.delivered_today || 0}</span>
              </div>
              <div className="flex items-center justify-between">
                <span className="text-muted-foreground">Returned</span>
                <span className="font-medium text-red-600">{metrics?.waybills?.returned_today || 0}</span>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="text-sm">Agent Status</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
              <div className="flex items-center justify-between">
                <span className="text-muted-foreground">Total Agents</span>
                <span className="font-medium">{metrics?.agents?.total || 0}</span>
              </div>
              <div className="flex items-center justify-between">
                <span className="text-muted-foreground">Currently Online</span>
                <Badge variant="default">{metrics?.agents?.online || 0}</Badge>
              </div>
              <div className="flex items-center justify-between">
                <span className="text-muted-foreground">Avg Performance</span>
                <span className="font-medium">{metrics?.agents?.avg_performance || 0}%</span>
              </div>
              <div className="flex items-center justify-between">
                <span className="text-muted-foreground">Top Performer</span>
                <span className="font-medium text-primary">{metrics?.agents?.top_performer || '-'}</span>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="text-sm">Revenue Summary</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
              <div className="flex items-center justify-between">
                <span className="text-muted-foreground">Today</span>
                <span className="font-medium">₱{(metrics?.revenue?.today || 0).toLocaleString()}</span>
              </div>
              <div className="flex items-center justify-between">
                <span className="text-muted-foreground">This Week</span>
                <span className="font-medium">₱{(metrics?.revenue?.this_week || 0).toLocaleString()}</span>
              </div>
              <div className="flex items-center justify-between">
                <span className="text-muted-foreground">This Month</span>
                <span className="font-medium text-green-600">₱{(metrics?.revenue?.this_month || 0).toLocaleString()}</span>
              </div>
            </CardContent>
          </Card>
        </div>
      </div>
    </AppLayout>
  );
}
