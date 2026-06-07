import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import {
  Clock, AlertTriangle, TrendingUp, Users, Activity, ArrowUpDown,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { ChartCard } from '@/components/ChartCard';
import { KpiCard } from '@/components/KpiCard';

interface UtilizationItem {
  agent_id: number;
  name: string;
  active: number;
  max: number;
  utilization: number;
  today_assigned: number;
  today_converted: number;
}

interface RebalancingItem {
  agent_id: number;
  name: string;
  weight: number;
  assigned: number;
  converted: number;
  expected_rate: number;
  actual_rate: number;
  skew: number;
}

interface Props {
  timeToAssign: number;
  timeDistribution: Record<string, number>;
  utilization: UtilizationItem[];
  queueDepth: Record<string, { pending: number; assigned: number; failed: number }>;
  queueSnapshot: { pending: number; assigned: number; failed: number; total_today: number };
  strategyPerformance: Record<string, { total: number; converted: number; rate: number }>;
  alerts: { capacity_alerts: { agent_id: number; name: string; active: number; max: number; utilization: number }[]; backlog_alert: boolean; queue_depth: number };
  rebalancing: RebalancingItem[];
  days: number;
}

export default function DistributionAnalytics({
  timeToAssign,
  timeDistribution,
  utilization,
  queueDepth,
  queueSnapshot,
  strategyPerformance,
  alerts,
  rebalancing,
  days,
}: Props) {
  const [selectedDays, setSelectedDays] = useState(days);

  const handleDaysChange = (d: number) => {
    setSelectedDays(d);
    router.get('/distribution/analytics', { days: d }, { preserveState: true, replace: true });
  };

  const queueChartData = Object.entries(queueDepth).map(([hour, vals]) => ({
    hour: hour.slice(11, 16),
    pending: vals.pending,
    assigned: vals.assigned,
    failed: vals.failed,
  }));

  const strategyData = Object.entries(strategyPerformance).map(([strategy, vals]) => ({
    strategy: strategy.replace('_', ' '),
    rate: vals.rate,
    total: vals.total,
  }));

  const timeDistData = Object.entries(timeDistribution).map(([bucket, count]) => ({
    bucket,
    count,
  }));

  return (
    <AppLayout>
      <Head title="Distribution Analytics" />
      <div className="space-y-6">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold tracking-tight">Distribution Analytics</h1>
            <p className="text-sm text-muted-foreground">
              Performance metrics, queue health, and strategy comparison.
            </p>
          </div>
          <div className="flex items-center gap-2">
            {[7, 14, 30].map((d) => (
              <Button
                key={d}
                size="sm"
                variant={selectedDays === d ? 'default' : 'outline'}
                onClick={() => handleDaysChange(d)}
              >
                {d}d
              </Button>
            ))}
          </div>
        </div>

        {/* Alerts */}
        {(alerts.backlog_alert || alerts.capacity_alerts.length > 0) && (
          <div className="space-y-2">
            {alerts.backlog_alert && (
              <div className="flex items-center gap-2 rounded-lg border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">
                <AlertTriangle className="h-4 w-4" />
                <span>Queue backlog: {alerts.queue_depth} leads pending. Consider adding more agents or adjusting rules.</span>
              </div>
            )}
            {alerts.capacity_alerts.map((a) => (
              <div key={a.agent_id} className="flex items-center gap-2 rounded-lg border border-yellow-500/50 bg-yellow-500/10 p-3 text-sm text-yellow-700">
                <AlertTriangle className="h-4 w-4" />
                <span>{a.name} is at {Math.round(a.utilization * 100)}% capacity ({a.active}/{a.max} leads).</span>
              </div>
            ))}
          </div>
        )}

        {/* KPIs */}
        <div className="grid gap-4 md:grid-cols-4">
          <KpiCard
            title="Avg Time-to-Assign"
            value={`${timeToAssign} min`}
            icon={<Clock className="h-4 w-4 text-muted-foreground" />}
            variant={timeToAssign <= 5 ? 'success' : timeToAssign <= 15 ? 'default' : 'destructive'}
          />
          <KpiCard
            title="Queue Pending"
            value={queueSnapshot.pending}
            subtitle={`${queueSnapshot.total_today} processed today`}
            icon={<Activity className="h-4 w-4 text-muted-foreground" />}
            variant={queueSnapshot.pending > 50 ? 'destructive' : 'default'}
          />
          <KpiCard
            title="Active Agents"
            value={utilization.length}
            subtitle={`${utilization.filter((u) => u.utilization > 0).length} with leads`}
            icon={<Users className="h-4 w-4 text-muted-foreground" />}
          />
          <KpiCard
            title="Avg Conversion"
            value={`${strategyData.length > 0 ? Math.round(strategyData.reduce((s, d) => s + d.rate, 0) / strategyData.length) : 0}%`}
            icon={<TrendingUp className="h-4 w-4 text-muted-foreground" />}
            variant="success"
          />
        </div>

        {/* Charts */}
        <div className="grid gap-6 lg:grid-cols-2">
          <ChartCard title="Queue Depth (24h)" data={queueChartData} type="area" dataKey="pending" xKey="hour" color="hsl(var(--primary))" height={220} />
          <ChartCard title="Strategy Conversion Rate" data={strategyData} type="bar" dataKey="rate" xKey="strategy" color="hsl(var(--primary))" height={220} />
        </div>

        <div className="grid gap-6 lg:grid-cols-2">
          <ChartCard title="Time-to-Assign Distribution" data={timeDistData} type="bar" dataKey="count" xKey="bucket" color="hsl(var(--primary))" height={220} />
          <Card>
            <CardHeader className="pb-2"><CardTitle className="text-sm font-medium">Agent Utilization</CardTitle></CardHeader>
            <CardContent className="space-y-2">
              {utilization.map((u) => (
                <div key={u.agent_id} className="flex items-center gap-3">
                  <span className="w-24 text-xs truncate">{u.name}</span>
                  <div className="flex-1 h-2 bg-muted rounded-full overflow-hidden">
                    <div
                      className={`h-full rounded-full ${u.utilization >= 0.9 ? 'bg-red-500' : u.utilization >= 0.7 ? 'bg-yellow-500' : 'bg-green-500'}`}
                      style={{ width: `${Math.min(100, u.utilization * 100)}%` }}
                    />
                  </div>
                  <span className="text-xs text-muted-foreground w-12 text-right">{Math.round(u.utilization * 100)}%</span>
                </div>
              ))}
            </CardContent>
          </Card>
        </div>

        {/* Rebalancing Report */}
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-sm font-medium flex items-center gap-2">
              <ArrowUpDown className="h-4 w-4" />
              Weekly Rebalancing Report
            </CardTitle>
          </CardHeader>
          <CardContent>
            {rebalancing.length === 0 ? (
              <p className="text-sm text-muted-foreground py-4">No data yet.</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b text-left text-muted-foreground">
                      <th className="pb-2 font-medium">Agent</th>
                      <th className="pb-2 font-medium text-right">Weight</th>
                      <th className="pb-2 font-medium text-right">Assigned</th>
                      <th className="pb-2 font-medium text-right">Converted</th>
                      <th className="pb-2 font-medium text-right">Actual Rate</th>
                      <th className="pb-2 font-medium text-right">Skew</th>
                    </tr>
                  </thead>
                  <tbody>
                    {rebalancing.map((r) => (
                      <tr key={r.agent_id} className="border-b border-border/50">
                        <td className="py-2">{r.name}</td>
                        <td className="py-2 text-right">{r.weight.toFixed(2)}</td>
                        <td className="py-2 text-right">{r.assigned}</td>
                        <td className="py-2 text-right">{r.converted}</td>
                        <td className="py-2 text-right">{r.actual_rate}%</td>
                        <td className="py-2 text-right">
                          <Badge variant={r.skew > 0.2 ? 'default' : r.skew < -0.2 ? 'destructive' : 'outline'}>
                            {r.skew > 0 ? '+' : ''}{r.skew.toFixed(2)}
                          </Badge>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
