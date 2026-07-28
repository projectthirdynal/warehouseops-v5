import { Head, Link } from '@inertiajs/react';
import {
  ArrowLeft,
  Headphones,
  Clock,
  CheckCircle,
  AlertTriangle,
  TrendingUp,
  Star,
  BarChart3,
  Timer,
} from 'lucide-react';
import {
  BarChart,
  Bar,
  LineChart,
  Line,
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
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';

interface Overview {
  total: number;
  open: number;
  resolved: number;
  overdue: number;
}

interface ResolutionTime {
  avg_hours: number | null;
  min_hours: number | null;
  max_hours: number | null;
}

interface Sla {
  met: number;
  breached: number;
  pending_overdue: number;
  on_track: number;
  compliance_rate: number | null;
}

interface Satisfaction {
  rated: number;
  average: number | null;
  distribution: Record<string, number>;
}

interface TrendPoint {
  date: string;
  created: number;
  resolved: number;
}

interface Assignee {
  name: string;
  ticket_count: number;
}

interface Props {
  overview: Overview;
  resolutionTime: ResolutionTime;
  statusBreakdown: Record<string, number>;
  priorityBreakdown: Record<string, number>;
  categoryBreakdown: Record<string, number>;
  sla: Sla;
  satisfaction: Satisfaction;
  trend: TrendPoint[];
  topAssignees: Assignee[];
}

const STATUS_COLORS: Record<string, string> = {
  open: '#ef4444',
  in_progress: '#3b82f6',
  waiting: '#f59e0b',
  resolved: '#22c55e',
  closed: '#6b7280',
};

const PRIORITY_COLORS: Record<string, string> = {
  urgent: '#ef4444',
  high: '#f59e0b',
  medium: '#3b82f6',
  low: '#6b7280',
};

function formatHours(hours: number | null): string {
  if (hours === null) return '—';
  if (hours < 1) return '< 1h';
  if (hours < 24) return `${hours}h`;
  const days = Math.floor(hours / 24);
  const rem = hours % 24;
  return rem > 0 ? `${days}d ${rem}h` : `${days}d`;
}

function formatDateShort(dateStr: string): string {
  const d = new Date(dateStr);
  return `${d.getMonth() + 1}/${d.getDate()}`;
}

export default function TicketsAnalytics({
  overview,
  resolutionTime,
  statusBreakdown,
  priorityBreakdown,
  categoryBreakdown,
  sla,
  satisfaction,
  trend,
  topAssignees,
}: Props) {
  const statusData = Object.entries(statusBreakdown).map(([name, value]) => ({
    name: name.replace('_', ' '),
    value,
    fill: STATUS_COLORS[name] || '#6b7280',
  }));

  const priorityData = Object.entries(priorityBreakdown).map(([name, value]) => ({
    name,
    value,
    fill: PRIORITY_COLORS[name] || '#6b7280',
  }));

  const categoryData = Object.entries(categoryBreakdown).map(([name, value]) => ({
    name,
    value,
  }));

  const slaData = [
    { name: 'SLA Met', value: sla.met, fill: '#22c55e' },
    { name: 'SLA Breached', value: sla.breached, fill: '#ef4444' },
    { name: 'Pending Overdue', value: sla.pending_overdue, fill: '#f59e0b' },
    { name: 'On Track', value: sla.on_track, fill: '#3b82f6' },
  ].filter((d) => d.value > 0);

  const satisfactionData = [1, 2, 3, 4, 5].map((rating) => ({
    rating: `${rating}★`,
    count: satisfaction.distribution[String(rating)] || 0,
    fill: rating >= 4 ? '#22c55e' : rating === 3 ? '#f59e0b' : '#ef4444',
  }));

  const assigneeData = topAssignees.map((a) => ({
    name: a.name,
    tickets: a.ticket_count,
  }));

  return (
    <AppLayout>
      <Head title="Ticket Analytics" />
      <div className="mx-auto max-w-7xl space-y-6 p-4 md:p-6">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            <Button variant="ghost" size="icon" asChild>
              <Link href="/tickets">
                <ArrowLeft className="h-4 w-4" />
              </Link>
            </Button>
            <div>
              <h1 className="text-2xl font-bold tracking-tight flex items-center gap-2">
                <BarChart3 className="h-6 w-6" />
                Ticket Analytics
              </h1>
              <p className="text-sm text-muted-foreground">
                Resolution time, category breakdown, SLA compliance, and satisfaction metrics.
              </p>
            </div>
          </div>
        </div>

        {/* Overview Stat Cards */}
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">
                Total Tickets
              </CardTitle>
              <Headphones className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{overview.total}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">
                Open / Active
              </CardTitle>
              <Clock className="h-4 w-4 text-blue-500" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-blue-600">{overview.open}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">
                Resolved / Closed
              </CardTitle>
              <CheckCircle className="h-4 w-4 text-green-500" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-green-600">{overview.resolved}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">Overdue</CardTitle>
              <AlertTriangle className="h-4 w-4 text-red-500" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-red-600">{overview.overdue}</div>
            </CardContent>
          </Card>
        </div>

        {/* Resolution Time + SLA Compliance */}
        <div className="grid gap-4 md:grid-cols-2">
          <Card>
            <CardHeader>
              <CardTitle className="text-sm font-medium flex items-center gap-2">
                <Timer className="h-4 w-4" />
                Resolution Time
              </CardTitle>
              <CardDescription>Average time to resolve tickets (hours)</CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="grid grid-cols-3 gap-4 text-center">
                <div>
                  <p className="text-xs text-muted-foreground">Average</p>
                  <p className="text-xl font-bold">{formatHours(resolutionTime.avg_hours)}</p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground">Fastest</p>
                  <p className="text-xl font-bold text-green-600">
                    {formatHours(resolutionTime.min_hours)}
                  </p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground">Slowest</p>
                  <p className="text-xl font-bold text-red-600">
                    {formatHours(resolutionTime.max_hours)}
                  </p>
                </div>
              </div>
              {resolutionTime.avg_hours === null && (
                <p className="text-sm text-muted-foreground italic text-center">
                  No resolved tickets with resolution data yet.
                </p>
              )}
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="text-sm font-medium flex items-center gap-2">
                <TrendingUp className="h-4 w-4" />
                SLA Compliance
              </CardTitle>
              <CardDescription>On-time resolution rate</CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              {sla.compliance_rate !== null ? (
                <>
                  <div className="text-center">
                    <p className="text-3xl font-bold">{sla.compliance_rate}%</p>
                    <p className="text-xs text-muted-foreground">Compliance Rate</p>
                  </div>
                  <div className="grid grid-cols-2 gap-2 text-sm">
                    <div className="flex items-center justify-between rounded-md border p-2">
                      <span className="text-green-600">Met</span>
                      <Badge variant="outline">{sla.met}</Badge>
                    </div>
                    <div className="flex items-center justify-between rounded-md border p-2">
                      <span className="text-red-600">Breached</span>
                      <Badge variant="outline">{sla.breached}</Badge>
                    </div>
                    <div className="flex items-center justify-between rounded-md border p-2">
                      <span className="text-amber-600">Pending Overdue</span>
                      <Badge variant="outline">{sla.pending_overdue}</Badge>
                    </div>
                    <div className="flex items-center justify-between rounded-md border p-2">
                      <span className="text-blue-600">On Track</span>
                      <Badge variant="outline">{sla.on_track}</Badge>
                    </div>
                  </div>
                </>
              ) : (
                <p className="text-sm text-muted-foreground italic text-center">
                  No SLA data available yet.
                </p>
              )}
            </CardContent>
          </Card>
        </div>

        {/* Trend Chart */}
        <Card>
          <CardHeader>
            <CardTitle className="text-sm font-medium">
              Created vs Resolved (Last 30 Days)
            </CardTitle>
          </CardHeader>
          <CardContent>
            {trend.length > 0 ? (
              <ResponsiveContainer width="100%" height={300}>
                <LineChart data={trend}>
                  <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                  <XAxis dataKey="date" tickFormatter={formatDateShort} className="text-xs" />
                  <YAxis className="text-xs" allowDecimals={false} />
                  <Tooltip
                    labelFormatter={(label) => `Date: ${label}`}
                    contentStyle={{ borderRadius: '8px', border: '1px solid hsl(var(--border))' }}
                  />
                  <Line
                    type="monotone"
                    dataKey="created"
                    stroke="#3b82f6"
                    strokeWidth={2}
                    name="Created"
                    dot={false}
                  />
                  <Line
                    type="monotone"
                    dataKey="resolved"
                    stroke="#22c55e"
                    strokeWidth={2}
                    name="Resolved"
                    dot={false}
                  />
                </LineChart>
              </ResponsiveContainer>
            ) : (
              <p className="text-sm text-muted-foreground italic text-center py-8">
                No ticket data in the last 30 days.
              </p>
            )}
          </CardContent>
        </Card>

        {/* Status + Priority Breakdown */}
        <div className="grid gap-4 md:grid-cols-2">
          <Card>
            <CardHeader>
              <CardTitle className="text-sm font-medium">Status Breakdown</CardTitle>
            </CardHeader>
            <CardContent>
              {statusData.length > 0 ? (
                <ResponsiveContainer width="100%" height={250}>
                  <BarChart data={statusData}>
                    <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                    <XAxis dataKey="name" className="text-xs" />
                    <YAxis className="text-xs" allowDecimals={false} />
                    <Tooltip
                      contentStyle={{ borderRadius: '8px', border: '1px solid hsl(var(--border))' }}
                    />
                    <Bar dataKey="value" radius={[4, 4, 0, 0]}>
                      {statusData.map((entry, idx) => (
                        <Cell key={`cell-${idx}`} fill={entry.fill} />
                      ))}
                    </Bar>
                  </BarChart>
                </ResponsiveContainer>
              ) : (
                <p className="text-sm text-muted-foreground italic text-center py-8">No data.</p>
              )}
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="text-sm font-medium">Priority Breakdown</CardTitle>
            </CardHeader>
            <CardContent>
              {priorityData.length > 0 ? (
                <ResponsiveContainer width="100%" height={250}>
                  <BarChart data={priorityData}>
                    <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                    <XAxis dataKey="name" className="text-xs" />
                    <YAxis className="text-xs" allowDecimals={false} />
                    <Tooltip
                      contentStyle={{ borderRadius: '8px', border: '1px solid hsl(var(--border))' }}
                    />
                    <Bar dataKey="value" radius={[4, 4, 0, 0]}>
                      {priorityData.map((entry, idx) => (
                        <Cell key={`cell-${idx}`} fill={entry.fill} />
                      ))}
                    </Bar>
                  </BarChart>
                </ResponsiveContainer>
              ) : (
                <p className="text-sm text-muted-foreground italic text-center py-8">No data.</p>
              )}
            </CardContent>
          </Card>
        </div>

        {/* Category Breakdown + SLA Pie */}
        <div className="grid gap-4 md:grid-cols-2">
          <Card>
            <CardHeader>
              <CardTitle className="text-sm font-medium">Category Breakdown</CardTitle>
            </CardHeader>
            <CardContent>
              {categoryData.length > 0 ? (
                <ResponsiveContainer width="100%" height={250}>
                  <BarChart data={categoryData} layout="vertical">
                    <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                    <XAxis type="number" className="text-xs" allowDecimals={false} />
                    <YAxis dataKey="name" type="category" className="text-xs" width={80} />
                    <Tooltip
                      contentStyle={{ borderRadius: '8px', border: '1px solid hsl(var(--border))' }}
                    />
                    <Bar dataKey="value" radius={[0, 4, 4, 0]} fill="#8b5cf6" />
                  </BarChart>
                </ResponsiveContainer>
              ) : (
                <p className="text-sm text-muted-foreground italic text-center py-8">No data.</p>
              )}
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="text-sm font-medium">SLA Distribution</CardTitle>
            </CardHeader>
            <CardContent>
              {slaData.length > 0 ? (
                <ResponsiveContainer width="100%" height={250}>
                  <PieChart>
                    <Pie
                      data={slaData}
                      dataKey="value"
                      nameKey="name"
                      cx="50%"
                      cy="50%"
                      outerRadius={80}
                      label={(entry) => `${entry.name}: ${entry.value}`}
                    >
                      {slaData.map((entry, idx) => (
                        <Cell key={`cell-${idx}`} fill={entry.fill} />
                      ))}
                    </Pie>
                    <Tooltip
                      contentStyle={{ borderRadius: '8px', border: '1px solid hsl(var(--border))' }}
                    />
                  </PieChart>
                </ResponsiveContainer>
              ) : (
                <p className="text-sm text-muted-foreground italic text-center py-8">
                  No SLA data.
                </p>
              )}
            </CardContent>
          </Card>
        </div>

        {/* Satisfaction + Top Assignees */}
        <div className="grid gap-4 md:grid-cols-2">
          <Card>
            <CardHeader>
              <CardTitle className="text-sm font-medium flex items-center gap-2">
                <Star className="h-4 w-4" />
                Satisfaction Distribution
              </CardTitle>
              <CardDescription>
                {satisfaction.rated > 0
                  ? `${satisfaction.rated} rating(s) — Average: ${satisfaction.average}/5`
                  : 'No ratings yet'}
              </CardDescription>
            </CardHeader>
            <CardContent>
              {satisfaction.rated > 0 ? (
                <ResponsiveContainer width="100%" height={250}>
                  <BarChart data={satisfactionData}>
                    <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                    <XAxis dataKey="rating" className="text-xs" />
                    <YAxis className="text-xs" allowDecimals={false} />
                    <Tooltip
                      contentStyle={{ borderRadius: '8px', border: '1px solid hsl(var(--border))' }}
                    />
                    <Bar dataKey="count" radius={[4, 4, 0, 0]}>
                      {satisfactionData.map((entry, idx) => (
                        <Cell key={`cell-${idx}`} fill={entry.fill} />
                      ))}
                    </Bar>
                  </BarChart>
                </ResponsiveContainer>
              ) : (
                <p className="text-sm text-muted-foreground italic text-center py-8">
                  No satisfaction ratings submitted yet.
                </p>
              )}
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="text-sm font-medium">Top Assignees</CardTitle>
              <CardDescription>By total ticket count</CardDescription>
            </CardHeader>
            <CardContent>
              {assigneeData.length > 0 ? (
                <ResponsiveContainer width="100%" height={250}>
                  <BarChart data={assigneeData} layout="vertical">
                    <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                    <XAxis type="number" className="text-xs" allowDecimals={false} />
                    <YAxis dataKey="name" type="category" className="text-xs" width={100} />
                    <Tooltip
                      contentStyle={{ borderRadius: '8px', border: '1px solid hsl(var(--border))' }}
                    />
                    <Bar dataKey="tickets" radius={[0, 4, 4, 0]} fill="#3b82f6" />
                  </BarChart>
                </ResponsiveContainer>
              ) : (
                <p className="text-sm text-muted-foreground italic text-center py-8">
                  No assigned tickets yet.
                </p>
              )}
            </CardContent>
          </Card>
        </div>
      </div>
    </AppLayout>
  );
}
