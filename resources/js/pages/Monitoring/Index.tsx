import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { Users, Truck, Target, Activity, Calendar } from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { KpiCard } from '@/components/KpiCard';
import { ChartCard } from '@/components/ChartCard';
import { ActivityFeed, type ActivityItem } from '@/components/ActivityFeed';
import { usePolling } from '@/hooks/use-polling';
import { useToast } from '@/hooks/use-toast';

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

const sampleActivity: ActivityItem[] = [
  {
    id: '1',
    title: 'Waybill WB-2024-001 delivered',
    description: 'Receiver: Maria Santos',
    timestamp: '2 min ago',
    type: 'success',
  },
  {
    id: '2',
    title: 'New lead assigned',
    description: 'Agent: Juan dela Cruz',
    timestamp: '15 min ago',
    type: 'info',
  },
  {
    id: '3',
    title: 'Waybill WB-2024-002 dispatched',
    description: 'Courier: LBC',
    timestamp: '32 min ago',
    type: 'info',
  },
  {
    id: '4',
    title: 'Return processed',
    description: 'Waybill WB-2024-000 — RTS reason: No answer',
    timestamp: '1 hr ago',
    type: 'warning',
  },
  {
    id: '5',
    title: 'Bulk import completed',
    description: '245 waybills imported',
    timestamp: '2 hrs ago',
    type: 'success',
  },
];

export default function MonitoringIndex({ metrics, hourly_data }: Props) {
  const [period, setPeriod] = useState('today');
  const { success } = useToast();

  usePolling(() => {}, [period], { interval: 30000, enabled: true });

  const safeHourly = hourly_data?.length
    ? hourly_data
    : [
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
      ];

  return (
    <AppLayout>
      <Head title="Monitoring" />
      <div className="space-y-6">
        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div>
            <h1 className="text-2xl font-bold font-display tracking-tight">Monitoring Dashboard</h1>
            <p className="text-muted-foreground">Real-time analytics and performance tracking</p>
          </div>
          <div className="flex gap-2">
            <Select value={period} onValueChange={setPeriod}>
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
            <Button variant="outline" onClick={() => success('Custom range picker would open')}>
              <Calendar className="mr-2 h-4 w-4" />
              Custom Range
            </Button>
          </div>
        </div>

        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
          <KpiCard
            title="Leads Today"
            value={metrics?.leads?.new_today || 0}
            trend={metrics?.leads?.trend}
            trendLabel="vs yesterday"
            icon={<Users className="h-4 w-4 text-muted-foreground" />}
            variant={metrics?.leads?.trend && metrics.leads.trend >= 0 ? 'success' : 'default'}
          />
          <KpiCard
            title="Conversion Rate"
            value={`${metrics?.leads?.conversion_rate || 0}%`}
            subtitle={`${metrics?.leads?.converted || 0} of ${metrics?.leads?.total || 0} leads`}
            icon={<Target className="h-4 w-4 text-muted-foreground" />}
          />
          <KpiCard
            title="Deliveries Today"
            value={metrics?.waybills?.delivered_today || 0}
            subtitle={`${metrics?.waybills?.delivery_rate || 0}% delivery rate`}
            icon={<Truck className="h-4 w-4 text-muted-foreground" />}
            variant="success"
          />
          <KpiCard
            title="Revenue Today"
            value={`₱${(metrics?.revenue?.today || 0).toLocaleString()}`}
            trend={metrics?.revenue?.trend}
            icon={<Activity className="h-4 w-4 text-muted-foreground" />}
            variant={metrics?.revenue?.trend && metrics.revenue.trend >= 0 ? 'success' : 'default'}
          />
        </div>

        <div className="grid gap-6 lg:grid-cols-2">
          <ChartCard
            title="Hourly Activity"
            data={safeHourly}
            type="area"
            dataKey="leads"
            xKey="hour"
            color="hsl(var(--primary))"
            height={260}
          />
          <ChartCard
            title="Sales Volume"
            data={safeHourly}
            type="bar"
            dataKey="sales"
            xKey="hour"
            color="hsl(var(--primary))"
            height={260}
          />
        </div>

        <div className="grid gap-6 lg:grid-cols-3">
          <Card>
            <CardHeader>
              <CardTitle className="text-sm">Waybill Status</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
              <div className="flex justify-between">
                <span className="text-muted-foreground">Pending</span>
                <span className="font-medium">{metrics?.waybills?.total || 0}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-muted-foreground">Dispatched</span>
                <span className="font-medium">{metrics?.waybills?.dispatched_today || 0}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-muted-foreground">Delivered</span>
                <span className="font-medium text-success">
                  {metrics?.waybills?.delivered_today || 0}
                </span>
              </div>
              <div className="flex justify-between">
                <span className="text-muted-foreground">Returned</span>
                <span className="font-medium text-destructive">
                  {metrics?.waybills?.returned_today || 0}
                </span>
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader>
              <CardTitle className="text-sm">Revenue Summary</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
              <div className="flex justify-between">
                <span className="text-muted-foreground">Today</span>
                <span className="font-medium">
                  ₱{(metrics?.revenue?.today || 0).toLocaleString()}
                </span>
              </div>
              <div className="flex justify-between">
                <span className="text-muted-foreground">This Week</span>
                <span className="font-medium">
                  ₱{(metrics?.revenue?.this_week || 0).toLocaleString()}
                </span>
              </div>
              <div className="flex justify-between">
                <span className="text-muted-foreground">This Month</span>
                <span className="font-medium text-success">
                  ₱{(metrics?.revenue?.this_month || 0).toLocaleString()}
                </span>
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader>
              <CardTitle className="text-sm">Recent Activity</CardTitle>
            </CardHeader>
            <CardContent>
              <ActivityFeed items={sampleActivity} />
            </CardContent>
          </Card>
        </div>
      </div>
    </AppLayout>
  );
}
