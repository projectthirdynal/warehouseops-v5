import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import axios from 'axios';
import { toast } from 'sonner';
import {
  Clock,
  AlertTriangle,
  TrendingUp,
  Users,
  Activity,
  ArrowUpDown,
  Scale,
  Zap,
  Loader2,
} from 'lucide-react';
import TelesalesLayout from '@/layouts/TelesalesLayout';
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

interface FairnessShare {
  agent_id: number;
  name: string;
  assigned: number;
  actual_share: number;
  expected_share: number;
  weight: number;
  deviation: number;
}

interface FairnessData {
  gini: number;
  total_assigned: number;
  agent_count: number;
  shares: FairnessShare[];
  status: string;
}

interface ImbalanceAlert {
  agent_id: number;
  name: string;
  type: string;
  assigned: number;
  actual_share: number;
  expected_share: number;
  deviation: number;
  severity: string;
}

interface FairnessTrendPoint {
  date: string;
  gini: number;
  total_assigned: number;
}

interface Props {
  timeToAssign: number;
  timeDistribution: Record<string, number>;
  utilization: UtilizationItem[];
  queueDepth: Record<string, { pending: number; assigned: number; failed: number }>;
  queueSnapshot: { pending: number; assigned: number; failed: number; total_today: number };
  strategyPerformance: Record<string, { total: number; converted: number; rate: number }>;
  alerts: {
    capacity_alerts: {
      agent_id: number;
      name: string;
      active: number;
      max: number;
      utilization: number;
    }[];
    backlog_alert: boolean;
    queue_depth: number;
  };
  rebalancing: RebalancingItem[];
  fairness: FairnessData;
  imbalanceAlerts: ImbalanceAlert[];
  fairnessTrend: FairnessTrendPoint[];
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
  fairness,
  imbalanceAlerts,
  fairnessTrend,
  days,
}: Props) {
  const [selectedDays, setSelectedDays] = useState(days);
  const [rebalancing_, setRebalancing] = useState(false);

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

  const fairnessTrendData = fairnessTrend.map((p) => ({
    date: p.date.slice(5),
    gini: p.gini,
    total_assigned: p.total_assigned,
  }));

  const fairnessStatusVariant: Record<string, string> = {
    fair: 'success',
    warning: 'warning',
    imbalanced: 'default',
    critical: 'destructive',
  };

  const severityVariant: Record<string, string> = {
    low: 'outline',
    medium: 'default',
    high: 'warning',
    critical: 'destructive',
  };

  const handleApplyRebalancing = async () => {
    setRebalancing(true);
    try {
      const res = await axios.post('/distribution/analytics/rebalance', { threshold: 0.15 });
      toast.success(
        `Rebalancing complete: ${res.data.adjusted} agents adjusted, ${res.data.skipped} skipped`
      );
      router.reload();
    } catch {
      toast.error('Rebalancing failed. Please try again.');
    } finally {
      setRebalancing(false);
    }
  };

  return (
    <TelesalesLayout>
      <Head title="Distribution Analytics" />
      <div className="space-y-4">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-xl font-bold font-display tracking-tight">
              Distribution Analytics
            </h1>
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
                <span>
                  Queue backlog: {alerts.queue_depth} leads pending. Consider adding more agents or
                  adjusting rules.
                </span>
              </div>
            )}
            {alerts.capacity_alerts.map((a) => (
              <div
                key={a.agent_id}
                className="flex items-center gap-2 rounded-lg border border-warning/50 bg-warning/50/10 p-3 text-sm text-warning"
              >
                <AlertTriangle className="h-4 w-4" />
                <span>
                  {a.name} is at {Math.round(a.utilization * 100)}% capacity ({a.active}/{a.max}{' '}
                  leads).
                </span>
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
          <ChartCard
            title="Queue Depth (24h)"
            data={queueChartData}
            type="area"
            dataKey="pending"
            xKey="hour"
            color="hsl(var(--primary))"
            height={220}
          />
          <ChartCard
            title="Strategy Conversion Rate"
            data={strategyData}
            type="bar"
            dataKey="rate"
            xKey="strategy"
            color="hsl(var(--primary))"
            height={220}
          />
        </div>

        <div className="grid gap-6 lg:grid-cols-2">
          <ChartCard
            title="Time-to-Assign Distribution"
            data={timeDistData}
            type="bar"
            dataKey="count"
            xKey="bucket"
            color="hsl(var(--primary))"
            height={220}
          />
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium">Agent Utilization</CardTitle>
            </CardHeader>
            <CardContent className="space-y-2">
              {utilization.map((u) => (
                <div key={u.agent_id} className="flex items-center gap-3">
                  <span className="w-24 text-xs truncate">{u.name}</span>
                  <div className="flex-1 h-2 bg-muted rounded-full overflow-hidden">
                    <div
                      className={`h-full rounded-full ${u.utilization >= 0.9 ? 'bg-destructive/50' : u.utilization >= 0.7 ? 'bg-warning/50' : 'bg-success/50'}`}
                      style={{ width: `${Math.min(100, u.utilization * 100)}%` }}
                    />
                  </div>
                  <span className="text-xs text-muted-foreground w-12 text-right">
                    {Math.round(u.utilization * 100)}%
                  </span>
                </div>
              ))}
            </CardContent>
          </Card>
        </div>

        {/* Fairness Metrics */}
        <div className="grid gap-6 lg:grid-cols-3">
          <Card className="lg:col-span-1">
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium flex items-center gap-2">
                <Scale className="h-4 w-4" />
                Distribution Fairness
              </CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
              <div className="flex items-center justify-between">
                <span className="text-sm text-muted-foreground">Gini Coefficient</span>
                <span className="text-2xl font-bold">{fairness.gini.toFixed(3)}</span>
              </div>
              <div className="flex items-center justify-between">
                <span className="text-sm text-muted-foreground">Status</span>
                <Badge
                  variant={
                    fairnessStatusVariant[fairness.status] as
                      | 'default'
                      | 'destructive'
                      | 'success'
                      | 'warning'
                      | 'outline'
                  }
                >
                  {fairness.status}
                </Badge>
              </div>
              <div className="flex items-center justify-between">
                <span className="text-sm text-muted-foreground">Total Assigned</span>
                <span className="text-sm font-medium">{fairness.total_assigned}</span>
              </div>
              <div className="flex items-center justify-between">
                <span className="text-sm text-muted-foreground">Active Agents</span>
                <span className="text-sm font-medium">{fairness.agent_count}</span>
              </div>
            </CardContent>
          </Card>

          <Card className="lg:col-span-2">
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium">Share vs Expected</CardTitle>
            </CardHeader>
            <CardContent className="space-y-2">
              {fairness.shares.length === 0 ? (
                <p className="text-sm text-muted-foreground py-4">No data yet.</p>
              ) : (
                fairness.shares.map((s) => (
                  <div key={s.agent_id} className="flex items-center gap-3">
                    <span className="w-24 text-xs truncate">{s.name}</span>
                    <div className="flex-1 space-y-0.5">
                      <div className="flex items-center gap-1">
                        <div className="flex-1 h-2 bg-muted rounded-full overflow-hidden">
                          <div
                            className="h-full rounded-full bg-primary"
                            style={{ width: `${Math.min(100, s.actual_share * 100)}%` }}
                          />
                        </div>
                        <span className="text-xs text-muted-foreground w-10 text-right">
                          {(s.actual_share * 100).toFixed(0)}%
                        </span>
                      </div>
                      <div className="flex items-center gap-1">
                        <div className="flex-1 h-1 bg-muted rounded-full overflow-hidden">
                          <div
                            className="h-full rounded-full bg-muted-foreground/40"
                            style={{ width: `${Math.min(100, s.expected_share * 100)}%` }}
                          />
                        </div>
                        <span className="text-xs text-muted-foreground/60 w-10 text-right">
                          {(s.expected_share * 100).toFixed(0)}%
                        </span>
                      </div>
                    </div>
                    <span
                      className={`text-xs w-12 text-right font-medium ${s.deviation > 0.05 ? 'text-destructive' : s.deviation < -0.05 ? 'text-warning' : 'text-muted-foreground'}`}
                    >
                      {s.deviation > 0 ? '+' : ''}
                      {(s.deviation * 100).toFixed(1)}%
                    </span>
                  </div>
                ))
              )}
            </CardContent>
          </Card>
        </div>

        {/* Fairness Trend */}
        <ChartCard
          title="Fairness Trend (Gini Coefficient)"
          data={fairnessTrendData}
          type="area"
          dataKey="gini"
          xKey="date"
          color="hsl(var(--primary))"
          height={200}
        />

        {/* Imbalance Alerts */}
        {imbalanceAlerts.length > 0 && (
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium flex items-center gap-2">
                <AlertTriangle className="h-4 w-4" />
                Imbalance Alerts
                <Badge variant="destructive">{imbalanceAlerts.length}</Badge>
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b text-left text-muted-foreground">
                      <th className="pb-2 font-medium">Agent</th>
                      <th className="pb-2 font-medium">Type</th>
                      <th className="pb-2 font-medium text-right">Assigned</th>
                      <th className="pb-2 font-medium text-right">Actual Share</th>
                      <th className="pb-2 font-medium text-right">Expected Share</th>
                      <th className="pb-2 font-medium text-right">Deviation</th>
                      <th className="pb-2 font-medium text-right">Severity</th>
                    </tr>
                  </thead>
                  <tbody>
                    {imbalanceAlerts.map((a) => (
                      <tr key={a.agent_id} className="border-b border-border/50">
                        <td className="py-2">{a.name}</td>
                        <td className="py-2">
                          <Badge variant={a.type === 'over_assigned' ? 'destructive' : 'warning'}>
                            {a.type.replace('_', ' ')}
                          </Badge>
                        </td>
                        <td className="py-2 text-right">{a.assigned}</td>
                        <td className="py-2 text-right">{(a.actual_share * 100).toFixed(1)}%</td>
                        <td className="py-2 text-right">{(a.expected_share * 100).toFixed(1)}%</td>
                        <td className="py-2 text-right">
                          {a.deviation > 0 ? '+' : ''}
                          {(a.deviation * 100).toFixed(1)}%
                        </td>
                        <td className="py-2 text-right">
                          <Badge
                            variant={
                              severityVariant[a.severity] as
                                | 'default'
                                | 'destructive'
                                | 'success'
                                | 'warning'
                                | 'outline'
                            }
                          >
                            {a.severity}
                          </Badge>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </CardContent>
          </Card>
        )}

        {/* Rebalancing Report */}
        <Card>
          <CardHeader className="pb-2">
            <div className="flex items-center justify-between">
              <CardTitle className="text-sm font-medium flex items-center gap-2">
                <ArrowUpDown className="h-4 w-4" />
                Weekly Rebalancing Report
              </CardTitle>
              <Button size="sm" onClick={handleApplyRebalancing} disabled={rebalancing_}>
                {rebalancing_ ? (
                  <Loader2 className="h-3 w-3 animate-spin" />
                ) : (
                  <Zap className="h-3 w-3" />
                )}
                Auto-Rebalance
              </Button>
            </div>
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
                          <Badge
                            variant={
                              r.skew > 0.2 ? 'default' : r.skew < -0.2 ? 'destructive' : 'outline'
                            }
                          >
                            {r.skew > 0 ? '+' : ''}
                            {r.skew.toFixed(2)}
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
    </TelesalesLayout>
  );
}
