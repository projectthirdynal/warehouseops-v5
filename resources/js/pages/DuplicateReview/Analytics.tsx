import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
  AlertTriangle,
  ArrowLeft,
  BarChart3,
  Users,
  ShoppingCart,
  MessageSquare,
  Clock,
  CheckCircle,
  Activity,
} from 'lucide-react';

interface Overview {
  period_days: number;
  total_items: number;
  items_in_period: number;
  by_type: Record<string, number>;
  by_status: Record<string, number>;
  by_severity: Record<string, number>;
  by_match_method: Record<string, number>;
  resolution_rate: number;
  avg_resolution_hours: number | null;
  top_reviewers: Array<{ user_id: number; name: string; count: number }>;
  active_rules: number;
  total_rules: number;
}

interface TrendPoint {
  date: string;
  created: number;
  resolved: number;
  cumulative_created: number;
  cumulative_resolved: number;
  backlog: number;
}

interface Breakdown {
  total: number;
  pending: number;
  reviewed: number;
  dismissed: number;
  actioned: number;
  by_severity: Record<string, number>;
  by_match_method: Record<string, number>;
}

interface Props {
  overview: Overview;
  trend: TrendPoint[];
  breakdown: Record<string, Breakdown>;
  days: number;
}

const typeIcons: Record<string, typeof Users> = {
  order: ShoppingCart,
  customer: Users,
  conversation: MessageSquare,
};

const typeLabels: Record<string, string> = {
  order: 'Orders',
  customer: 'Customers',
  conversation: 'Conversations',
};

const severityColors: Record<string, string> = {
  high: 'bg-destructive/10 text-destructive',
  medium: 'bg-warning/10 text-warning',
  low: 'bg-info/10 text-info',
};

const statusColors: Record<string, string> = {
  pending: 'bg-secondary text-secondary-foreground',
  reviewed: 'bg-info/10 text-info',
  dismissed: 'bg-muted text-muted-foreground',
  actioned: 'bg-primary/10 text-primary',
};

export default function DuplicateReviewAnalytics({ overview, trend, breakdown, days }: Props) {
  const [selectedDays, setSelectedDays] = useState(days);

  const changeDays = (newDays: number) => {
    setSelectedDays(newDays);
    router.get(`/shop/duplicate-review/analytics?days=${newDays}`, {}, { preserveScroll: true });
  };

  const maxTrendValue = Math.max(...trend.map((t) => Math.max(t.created, t.resolved)), 1);
  const maxCumulative = Math.max(...trend.map((t) => t.cumulative_created), 1);

  return (
    <AppLayout>
      <div className="space-y-6 p-6">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="flex items-center gap-2 text-2xl font-bold">
              <BarChart3 className="h-7 w-7 text-info" />
              Duplicate Analytics
            </h1>
            <p className="mt-1 text-sm text-muted-foreground">
              Track duplicate detection metrics, resolution trends, and system health.
            </p>
          </div>
          <div className="flex gap-2">
            <Link href="/shop/duplicate-review">
              <Button variant="outline" size="sm">
                <ArrowLeft className="mr-1.5 h-4 w-4" />
                Back to Queue
              </Button>
            </Link>
            <Link href="/shop/duplicate-review/rules">
              <Button variant="outline" size="sm">
                Rules
              </Button>
            </Link>
          </div>
        </div>

        {/* Period Selector */}
        <div className="flex items-center gap-2">
          <span className="text-sm text-muted-foreground">Period:</span>
          {[7, 14, 30, 90].map((d) => (
            <button
              key={d}
              onClick={() => changeDays(d)}
              className={`rounded-md px-3 py-1 text-sm ${
                selectedDays === d
                  ? 'bg-primary text-primary-foreground'
                  : 'bg-muted text-muted-foreground hover:bg-muted/80'
              }`}
            >
              {d} days
            </button>
          ))}
        </div>

        {/* KPI Cards */}
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <p className="text-xs text-muted-foreground">Total Items</p>
                <AlertTriangle className="h-4 w-4 text-muted-foreground" />
              </div>
              <p className="text-2xl font-bold">{overview.total_items}</p>
              <p className="text-xs text-muted-foreground">
                {overview.items_in_period} in last {overview.period_days}d
              </p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <p className="text-xs text-muted-foreground">Resolution Rate</p>
                <CheckCircle className="h-4 w-4 text-green-500" />
              </div>
              <p className="text-2xl font-bold text-green-600">{overview.resolution_rate}%</p>
              <p className="text-xs text-muted-foreground">
                {(overview.by_status['reviewed'] ?? 0) + (overview.by_status['actioned'] ?? 0)}{' '}
                resolved
              </p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <p className="text-xs text-muted-foreground">Avg Resolution Time</p>
                <Clock className="h-4 w-4 text-muted-foreground" />
              </div>
              <p className="text-2xl font-bold">
                {overview.avg_resolution_hours !== null ? `${overview.avg_resolution_hours}h` : '—'}
              </p>
              <p className="text-xs text-muted-foreground">from creation to review</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <p className="text-xs text-muted-foreground">Active Rules</p>
                <Activity className="h-4 w-4 text-info" />
              </div>
              <p className="text-2xl font-bold">
                {overview.active_rules}
                <span className="text-base text-muted-foreground">/{overview.total_rules}</span>
              </p>
              <p className="text-xs text-muted-foreground">detection rules enabled</p>
            </CardContent>
          </Card>
        </div>

        {/* By Type & By Status */}
        <div className="grid gap-4 lg:grid-cols-2">
          <Card>
            <CardHeader>
              <CardTitle className="text-base">Items by Type</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="space-y-3">
                {['order', 'customer', 'conversation'].map((type) => {
                  const Icon = typeIcons[type] ?? AlertTriangle;
                  const count = overview.by_type[type] ?? 0;
                  const pct = overview.total_items > 0 ? (count / overview.total_items) * 100 : 0;
                  return (
                    <div key={type} className="flex items-center gap-3">
                      <Icon className="h-5 w-5 text-muted-foreground" />
                      <div className="flex-1">
                        <div className="flex items-center justify-between text-sm">
                          <span>{typeLabels[type] ?? type}</span>
                          <span className="font-medium">{count}</span>
                        </div>
                        <div className="mt-1 h-2 rounded-full bg-muted">
                          <div
                            className="h-2 rounded-full bg-primary"
                            style={{ width: `${pct}%` }}
                          />
                        </div>
                      </div>
                    </div>
                  );
                })}
                {overview.total_items === 0 && (
                  <p className="text-sm text-muted-foreground">No items yet.</p>
                )}
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="text-base">Items by Status</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="space-y-3">
                {['pending', 'reviewed', 'actioned', 'dismissed'].map((status) => {
                  const count = overview.by_status[status] ?? 0;
                  const pct = overview.total_items > 0 ? (count / overview.total_items) * 100 : 0;
                  return (
                    <div key={status}>
                      <div className="flex items-center justify-between text-sm">
                        <Badge className={statusColors[status] ?? ''}>{status}</Badge>
                        <span className="font-medium">{count}</span>
                      </div>
                      <div className="mt-1 h-2 rounded-full bg-muted">
                        <div
                          className="h-2 rounded-full bg-primary/60"
                          style={{ width: `${pct}%` }}
                        />
                      </div>
                    </div>
                  );
                })}
                {overview.total_items === 0 && (
                  <p className="text-sm text-muted-foreground">No items yet.</p>
                )}
              </div>
            </CardContent>
          </Card>
        </div>

        {/* By Severity & By Match Method */}
        <div className="grid gap-4 lg:grid-cols-2">
          <Card>
            <CardHeader>
              <CardTitle className="text-base">Items by Severity</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="flex flex-wrap gap-3">
                {['high', 'medium', 'low'].map((sev) => {
                  const count = overview.by_severity[sev] ?? 0;
                  return (
                    <div
                      key={sev}
                      className={`flex items-center gap-2 rounded-lg p-3 ${severityColors[sev] ?? ''}`}
                    >
                      <span className="text-2xl font-bold">{count}</span>
                      <span className="text-xs uppercase">{sev}</span>
                    </div>
                  );
                })}
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="text-base">Items by Match Method</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="space-y-2">
                {Object.entries(overview.by_match_method).length > 0 ? (
                  Object.entries(overview.by_match_method)
                    .sort(([, a], [, b]) => b - a)
                    .map(([method, count]) => (
                      <div key={method} className="flex items-center justify-between text-sm">
                        <span className="font-mono text-xs">{method}</span>
                        <div className="flex items-center gap-2">
                          <span className="font-medium">{count}</span>
                          <div className="h-1.5 w-20 rounded-full bg-muted">
                            <div
                              className="h-1.5 rounded-full bg-info"
                              style={{
                                width: `${overview.total_items > 0 ? (count / overview.total_items) * 100 : 0}%`,
                              }}
                            />
                          </div>
                        </div>
                      </div>
                    ))
                ) : (
                  <p className="text-sm text-muted-foreground">No match methods recorded.</p>
                )}
              </div>
            </CardContent>
          </Card>
        </div>

        {/* Trend Chart */}
        <Card>
          <CardHeader>
            <CardTitle className="text-base">
              Daily Trend (Last {overview.period_days} days)
            </CardTitle>
          </CardHeader>
          <CardContent>
            {trend.length > 0 ? (
              <div className="space-y-3">
                {/* Created vs Resolved bar chart */}
                <div className="space-y-1">
                  <p className="text-xs font-medium text-muted-foreground">
                    Created vs Resolved (daily)
                  </p>
                  <div className="flex items-end gap-1" style={{ height: '120px' }}>
                    {trend.map((point) => (
                      <div
                        key={point.date}
                        className="flex flex-1 flex-col items-center justify-end gap-0.5"
                        title={`${point.date}: ${point.created} created, ${point.resolved} resolved`}
                      >
                        <div
                          className="w-full rounded-t bg-destructive/40"
                          style={{ height: `${(point.created / maxTrendValue) * 100}%` }}
                        />
                        <div
                          className="w-full rounded-t bg-green-500/40"
                          style={{ height: `${(point.resolved / maxTrendValue) * 100}%` }}
                        />
                      </div>
                    ))}
                  </div>
                  <div className="flex items-center gap-4 text-xs text-muted-foreground">
                    <span className="flex items-center gap-1">
                      <span className="h-2 w-2 rounded bg-destructive/40" /> Created
                    </span>
                    <span className="flex items-center gap-1">
                      <span className="h-2 w-2 rounded bg-green-500/40" /> Resolved
                    </span>
                  </div>
                </div>

                {/* Backlog line */}
                <div className="space-y-1">
                  <p className="text-xs font-medium text-muted-foreground">
                    Cumulative Backlog ({trend[trend.length - 1]?.backlog ?? 0} pending)
                  </p>
                  <div className="flex items-end gap-1" style={{ height: '60px' }}>
                    {trend.map((point) => (
                      <div
                        key={point.date}
                        className="flex-1 rounded-t bg-warning/40"
                        style={{ height: `${(point.backlog / maxCumulative) * 100}%` }}
                        title={`${point.date}: backlog ${point.backlog}`}
                      />
                    ))}
                  </div>
                </div>

                {/* Summary stats */}
                <div className="grid grid-cols-3 gap-3 border-t pt-3">
                  <div>
                    <p className="text-xs text-muted-foreground">Total Created</p>
                    <p className="text-lg font-bold">
                      {trend.reduce((sum, t) => sum + t.created, 0)}
                    </p>
                  </div>
                  <div>
                    <p className="text-xs text-muted-foreground">Total Resolved</p>
                    <p className="text-lg font-bold text-green-600">
                      {trend.reduce((sum, t) => sum + t.resolved, 0)}
                    </p>
                  </div>
                  <div>
                    <p className="text-xs text-muted-foreground">Current Backlog</p>
                    <p className="text-lg font-bold text-warning">
                      {trend[trend.length - 1]?.backlog ?? 0}
                    </p>
                  </div>
                </div>
              </div>
            ) : (
              <p className="text-sm text-muted-foreground">
                No trend data for the selected period.
              </p>
            )}
          </CardContent>
        </Card>

        {/* Type Breakdown Table */}
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Breakdown by Type</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b text-left text-xs text-muted-foreground">
                    <th className="pb-2 pr-4">Type</th>
                    <th className="pb-2 pr-4">Total</th>
                    <th className="pb-2 pr-4">Pending</th>
                    <th className="pb-2 pr-4">Reviewed</th>
                    <th className="pb-2 pr-4">Actioned</th>
                    <th className="pb-2 pr-4">Dismissed</th>
                    <th className="pb-2">High Sev</th>
                  </tr>
                </thead>
                <tbody>
                  {['order', 'customer', 'conversation'].map((type) => {
                    const data = breakdown[type];
                    if (!data) return null;
                    const Icon = typeIcons[type] ?? AlertTriangle;
                    return (
                      <tr key={type} className="border-b">
                        <td className="py-2 pr-4">
                          <div className="flex items-center gap-2">
                            <Icon className="h-4 w-4 text-muted-foreground" />
                            {typeLabels[type] ?? type}
                          </div>
                        </td>
                        <td className="py-2 pr-4 font-medium">{data.total}</td>
                        <td className="py-2 pr-4 text-warning">{data.pending}</td>
                        <td className="py-2 pr-4 text-info">{data.reviewed}</td>
                        <td className="py-2 pr-4 text-primary">{data.actioned}</td>
                        <td className="py-2 pr-4 text-muted-foreground">{data.dismissed}</td>
                        <td className="py-2 text-destructive">{data.by_severity['high'] ?? 0}</td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </CardContent>
        </Card>

        {/* Top Reviewers */}
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Top Reviewers</CardTitle>
          </CardHeader>
          <CardContent>
            {overview.top_reviewers.length > 0 ? (
              <div className="space-y-2">
                {overview.top_reviewers.map((reviewer, idx) => (
                  <div key={reviewer.user_id} className="flex items-center gap-3 text-sm">
                    <span className="flex h-6 w-6 items-center justify-center rounded-full bg-muted text-xs font-medium">
                      {idx + 1}
                    </span>
                    <span className="flex-1 font-medium">{reviewer.name}</span>
                    <span className="text-muted-foreground">{reviewer.count} items reviewed</span>
                  </div>
                ))}
              </div>
            ) : (
              <p className="text-sm text-muted-foreground">No review activity yet.</p>
            )}
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
