import { Head, Link } from '@inertiajs/react';
import {
  BarChart3,
  Clock,
  CheckCircle2,
  Download,
  FileText,
  MessageSquare,
  Store,
  TrendingUp,
  Users,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

interface Stats {
  total_conversations: number;
  responded_conversations: number;
  resolved_conversations: number;
  response_rate: number;
  resolution_rate: number;
  avg_first_response_seconds: number | null;
  avg_resolution_seconds: number | null;
  median_first_response_seconds: number | null;
  median_resolution_seconds: number | null;
}

interface AgentStat {
  id: number;
  name: string;
  assigned_count: number;
  responded_count: number;
  resolved_count: number;
  avg_response_seconds: number | null;
  avg_resolution_seconds: number | null;
}

interface PageStat {
  id: number;
  page_name: string;
  page_id: string;
  total_conversations: number;
  responded_count: number;
  resolved_count: number;
  response_rate: number;
  resolution_rate: number;
  avg_response_seconds: number | null;
  avg_resolution_seconds: number | null;
}

interface DailyTrendItem {
  date: string;
  total: number;
  responded: number;
  resolved: number;
}

interface RecentExport {
  id: number;
  export_number: string;
  status: string;
  conversation_count: number;
  message_count: number;
  file_path: string | null;
  created_at: string;
}

interface Props {
  stats: Stats;
  per_agent: AgentStat[];
  per_page: PageStat[];
  status_distribution: Record<string, number>;
  sentiment_distribution: Record<string, number>;
  daily_trend: DailyTrendItem[];
  recent_exports: RecentExport[];
  range: string;
}

function formatDuration(seconds: number | null): string {
  if (seconds === null || seconds === undefined) return '—';
  if (seconds < 60) return `${seconds}s`;
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
  if (seconds < 86400)
    return `${Math.floor(seconds / 3600)}h ${Math.floor((seconds % 3600) / 60)}m`;
  return `${Math.floor(seconds / 86400)}d ${Math.floor((seconds % 86400) / 3600)}h`;
}

export default function ConversationAnalytics({
  stats,
  per_agent,
  per_page,
  status_distribution,
  sentiment_distribution,
  daily_trend,
  recent_exports,
  range,
}: Props) {
  const ranges = [
    { value: '7d', label: 'Last 7 days' },
    { value: '30d', label: 'Last 30 days' },
    { value: '90d', label: 'Last 90 days' },
  ];

  const maxDailyTotal = Math.max(...daily_trend.map((d) => d.total), 1);

  return (
    <AppLayout>
      <Head title="Conversation Analytics" />
      <div className="space-y-6 p-6">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold tracking-tight">Conversation Analytics</h1>
            <p className="text-sm text-muted-foreground">Response time and resolution metrics</p>
          </div>
          <div className="flex gap-2">
            {ranges.map((r) => (
              <Button
                key={r.value}
                variant={range === r.value ? 'default' : 'outline'}
                size="sm"
                asChild
              >
                <Link href={`/shop/analytics?range=${r.value}`}>{r.label}</Link>
              </Button>
            ))}
          </div>
        </div>

        {/* Summary stat cards */}
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium">Total Conversations</CardTitle>
              <MessageSquare className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{stats.total_conversations}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium">Response Rate</CardTitle>
              <TrendingUp className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{stats.response_rate}%</div>
              <p className="text-xs text-muted-foreground">
                {stats.responded_conversations} of {stats.total_conversations} responded
              </p>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium">Resolution Rate</CardTitle>
              <CheckCircle2 className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{stats.resolution_rate}%</div>
              <p className="text-xs text-muted-foreground">
                {stats.resolved_conversations} of {stats.total_conversations} resolved
              </p>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium">Avg First Response</CardTitle>
              <Clock className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">
                {formatDuration(stats.avg_first_response_seconds)}
              </div>
              <p className="text-xs text-muted-foreground">
                Median: {formatDuration(stats.median_first_response_seconds)}
              </p>
            </CardContent>
          </Card>
        </div>

        {/* Resolution time + status distribution */}
        <div className="grid gap-4 md:grid-cols-2">
          <Card>
            <CardHeader>
              <CardTitle>Resolution Time</CardTitle>
              <CardDescription>Average and median time to close conversations</CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="flex justify-between text-sm">
                <span className="text-muted-foreground">Average</span>
                <span className="font-medium">{formatDuration(stats.avg_resolution_seconds)}</span>
              </div>
              <div className="flex justify-between text-sm">
                <span className="text-muted-foreground">Median</span>
                <span className="font-medium">
                  {formatDuration(stats.median_resolution_seconds)}
                </span>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Status Distribution</CardTitle>
              <CardDescription>Conversations by current status</CardDescription>
            </CardHeader>
            <CardContent className="space-y-2">
              {Object.entries(status_distribution).map(([status, count]) => (
                <div key={status} className="flex items-center justify-between text-sm">
                  <Badge variant="outline">{status.replace(/_/g, ' ')}</Badge>
                  <span className="font-medium">{count}</span>
                </div>
              ))}
              {Object.keys(status_distribution).length === 0 && (
                <p className="text-sm text-muted-foreground">No data for this period.</p>
              )}
            </CardContent>
          </Card>
        </div>

        {/* Sentiment distribution */}
        <Card>
          <CardHeader>
            <CardTitle>Sentiment Distribution</CardTitle>
            <CardDescription>Conversations by sentiment classification</CardDescription>
          </CardHeader>
          <CardContent>
            <div className="flex flex-wrap gap-4">
              {(['positive', 'neutral', 'negative'] as const).map((sentiment) => {
                const count = sentiment_distribution[sentiment] ?? 0;
                const total = Object.values(sentiment_distribution).reduce((a, b) => a + b, 0);
                const pct = total > 0 ? Math.round((count / total) * 100) : 0;
                const colors: Record<string, string> = {
                  positive: 'bg-green-500',
                  neutral: 'bg-gray-400',
                  negative: 'bg-red-500',
                };
                return (
                  <div key={sentiment} className="flex-1 space-y-1">
                    <div className="flex items-center justify-between text-sm">
                      <span className="capitalize">{sentiment}</span>
                      <span className="font-medium">
                        {count} ({pct}%)
                      </span>
                    </div>
                    <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
                      <div
                        className={`h-full rounded-full ${colors[sentiment]}`}
                        style={{ width: `${pct}%` }}
                      />
                    </div>
                  </div>
                );
              })}
              {Object.keys(sentiment_distribution).length === 0 && (
                <p className="text-sm text-muted-foreground">No data for this period.</p>
              )}
            </div>
          </CardContent>
        </Card>

        {/* Daily trend chart */}
        <Card>
          <CardHeader>
            <CardTitle>Daily Trend</CardTitle>
            <CardDescription>
              Conversations created, responded, and resolved per day
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div className="space-y-2">
              {daily_trend.map((day) => (
                <div key={day.date} className="flex items-center gap-3 text-sm">
                  <span className="w-28 shrink-0 text-muted-foreground">{day.date}</span>
                  <div className="flex flex-1 items-center gap-2">
                    <div
                      className="h-5 rounded bg-primary/20"
                      style={{ width: `${(day.total / maxDailyTotal) * 100}%`, minWidth: '2px' }}
                    />
                    <span className="shrink-0 font-medium">{day.total}</span>
                  </div>
                  <span className="w-16 shrink-0 text-xs text-success">{day.responded} resp</span>
                  <span className="w-16 shrink-0 text-xs text-primary">{day.resolved} resl</span>
                </div>
              ))}
              {daily_trend.length === 0 && (
                <p className="text-sm text-muted-foreground">No data for this period.</p>
              )}
            </div>
          </CardContent>
        </Card>

        {/* Per-page breakdown */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Store className="h-5 w-5" />
              Per-Page Performance
            </CardTitle>
            <CardDescription>
              Conversations, response time, and resolution metrics by Facebook Page
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b text-left text-muted-foreground">
                    <th className="pb-2 pr-4 font-medium">Page</th>
                    <th className="pb-2 pr-4 font-medium">Conversations</th>
                    <th className="pb-2 pr-4 font-medium">Response Rate</th>
                    <th className="pb-2 pr-4 font-medium">Resolution Rate</th>
                    <th className="pb-2 pr-4 font-medium">Avg Response</th>
                    <th className="pb-2 pr-4 font-medium">Avg Resolution</th>
                  </tr>
                </thead>
                <tbody>
                  {per_page.map((page) => (
                    <tr key={page.id} className="border-b last:border-0">
                      <td className="py-2 pr-4 font-medium">{page.page_name}</td>
                      <td className="py-2 pr-4">{page.total_conversations}</td>
                      <td className="py-2 pr-4">{page.response_rate}%</td>
                      <td className="py-2 pr-4">{page.resolution_rate}%</td>
                      <td className="py-2 pr-4">{formatDuration(page.avg_response_seconds)}</td>
                      <td className="py-2 pr-4">{formatDuration(page.avg_resolution_seconds)}</td>
                    </tr>
                  ))}
                  {per_page.length === 0 && (
                    <tr>
                      <td colSpan={6} className="py-4 text-center text-muted-foreground">
                        No page data for this period.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          </CardContent>
        </Card>

        {/* Per-agent breakdown */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Users className="h-5 w-5" />
              Per-Agent Performance
            </CardTitle>
            <CardDescription>Response and resolution metrics by assigned agent</CardDescription>
          </CardHeader>
          <CardContent>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b text-left text-muted-foreground">
                    <th className="pb-2 pr-4 font-medium">Agent</th>
                    <th className="pb-2 pr-4 font-medium">Assigned</th>
                    <th className="pb-2 pr-4 font-medium">Responded</th>
                    <th className="pb-2 pr-4 font-medium">Resolved</th>
                    <th className="pb-2 pr-4 font-medium">Avg Response</th>
                    <th className="pb-2 pr-4 font-medium">Avg Resolution</th>
                  </tr>
                </thead>
                <tbody>
                  {per_agent.map((agent) => (
                    <tr key={agent.id} className="border-b last:border-0">
                      <td className="py-2 pr-4 font-medium">{agent.name}</td>
                      <td className="py-2 pr-4">{agent.assigned_count}</td>
                      <td className="py-2 pr-4">{agent.responded_count}</td>
                      <td className="py-2 pr-4">{agent.resolved_count}</td>
                      <td className="py-2 pr-4">{formatDuration(agent.avg_response_seconds)}</td>
                      <td className="py-2 pr-4">{formatDuration(agent.avg_resolution_seconds)}</td>
                    </tr>
                  ))}
                  {per_agent.length === 0 && (
                    <tr>
                      <td colSpan={6} className="py-4 text-center text-muted-foreground">
                        No agent data for this period.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          </CardContent>
        </Card>

        {/* Compliance Export */}
        <Card>
          <CardHeader>
            <CardTitle>Compliance Export</CardTitle>
            <CardDescription>
              Export conversation data (including messages) as CSV for compliance and record-keeping
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <form
              action="/shop/conversations/export"
              method="POST"
              className="flex flex-wrap items-end gap-3"
            >
              <input type="hidden" name="_token" value={(window as any).csrfToken ?? ''} />
              <div className="space-y-1">
                <label className="text-xs text-muted-foreground">Date From</label>
                <input
                  type="date"
                  name="date_from"
                  className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                />
              </div>
              <div className="space-y-1">
                <label className="text-xs text-muted-foreground">Date To</label>
                <input
                  type="date"
                  name="date_to"
                  className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                />
              </div>
              <div className="space-y-1">
                <label className="text-xs text-muted-foreground">Status</label>
                <select
                  name="status"
                  className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                >
                  <option value="">All</option>
                  <option value="open">Open</option>
                  <option value="assigned">Assigned</option>
                  <option value="resolved">Resolved</option>
                  <option value="closed">Closed</option>
                  <option value="archived">Archived</option>
                </select>
              </div>
              <div className="space-y-1">
                <label className="text-xs text-muted-foreground">Sentiment</label>
                <select
                  name="sentiment"
                  className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                >
                  <option value="">All</option>
                  <option value="positive">Positive</option>
                  <option value="neutral">Neutral</option>
                  <option value="negative">Negative</option>
                </select>
              </div>
              <Button type="submit" size="sm">
                <FileText className="mr-2 h-4 w-4" />
                Generate Export
              </Button>
            </form>

            {recent_exports.length > 0 && (
              <div className="space-y-2">
                <p className="text-sm font-medium">Recent Exports</p>
                <div className="space-y-1">
                  {recent_exports.map((exp) => (
                    <div
                      key={exp.id}
                      className="flex items-center justify-between rounded-md border p-2 text-sm"
                    >
                      <div className="flex items-center gap-2">
                        <FileText className="h-4 w-4 text-muted-foreground" />
                        <span className="font-medium">{exp.export_number}</span>
                        <Badge variant="outline">{exp.status}</Badge>
                        <span className="text-muted-foreground">
                          {exp.conversation_count} conv · {exp.message_count} msgs
                        </span>
                      </div>
                      <div className="flex items-center gap-2">
                        <span className="text-xs text-muted-foreground">
                          {new Date(exp.created_at).toLocaleDateString()}
                        </span>
                        {exp.file_path && (
                          <Button variant="ghost" size="sm" asChild>
                            <Link href={`/shop/conversations/export/${exp.id}/download`}>
                              <Download className="mr-1 h-3 w-3" />
                              Download
                            </Link>
                          </Button>
                        )}
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            )}
          </CardContent>
        </Card>

        <div className="flex justify-end">
          <Button variant="outline" size="sm" asChild>
            <Link href="/shop/inbox">
              <BarChart3 className="mr-2 h-4 w-4" />
              Back to Inbox
            </Link>
          </Button>
        </div>
      </div>
    </AppLayout>
  );
}
