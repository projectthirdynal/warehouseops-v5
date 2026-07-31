import { Head, Link } from '@inertiajs/react';
import {
  ArrowLeft,
  BarChart3,
  CalendarDays,
  ClipboardList,
  Clock,
  Filter,
  GitBranch,
  Inbox,
  Radio,
  Repeat,
  ShoppingCart,
  Store,
  Users,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface Summary {
  shop_orders: number;
  sales_today: number | string;
  orders_today: number;
  confirmed_orders: number;
  open_conversations: number;
  webhook_events_today: number;
}

interface PagePerformance {
  id: number;
  page_name: string;
  connected_status: string;
  webhook_status: string;
  conversations_count: number;
  converted_count: number;
  messages_count: number;
  orders_count: number;
  sales_total: number | string;
}

interface AgentPerformance {
  id: number;
  name: string;
  role: string;
  assigned_conversations: number;
  converted_conversations: number;
  orders_count: number;
  sales_total: number | string;
}

interface StatusTotal {
  status: string;
  total: number;
  sales_total?: number | string;
}

interface TopProduct {
  product_name: string;
  quantity_sold: number;
  orders_count: number;
  sales_total: number | string;
}

interface DailySale {
  date: string;
  label: string;
  orders_count: number;
  sales_total: number | string;
}

interface FunnelStage {
  stage: string;
  label: string;
  count: number;
  percentage: number;
}

interface FunnelDropOff {
  from: string;
  to: string;
  lost: number;
  rate: number;
}

interface FunnelData {
  stages: FunnelStage[];
  drop_off: FunnelDropOff[];
}

interface ResponseTimeData {
  avg_first_response_seconds: number | null;
  median_first_response_seconds: number | null;
  avg_resolution_seconds: number | null;
  median_resolution_seconds: number | null;
  response_distribution: { bucket: string; count: number }[];
  resolution_distribution: { bucket: string; count: number }[];
  by_agent: {
    agent_id: number | null;
    agent_name: string;
    avg_response_seconds: number | null;
    avg_resolution_seconds: number | null;
    conversations: number;
  }[];
}

interface PeakHoursData {
  hourly: { hour: number; count: number }[];
  by_day: { day: number; day_name: string; count: number }[];
  heatmap: { day: number; hour: number; count: number }[];
  peak_hours: { hour: number; count: number }[];
  total_messages: number;
}

interface RetentionData {
  new_customers: number;
  returning_customers: number;
  repeat_purchase_rate: number;
  avg_orders_per_customer: number;
  distribution: { order_count: number; customers: number }[];
  monthly: { month: string; new: number; returning: number }[];
}

interface Props {
  summary: Summary;
  page_performance: PagePerformance[];
  agent_performance: AgentPerformance[];
  conversation_statuses: StatusTotal[];
  order_statuses: StatusTotal[];
  top_products: TopProduct[];
  daily_sales: DailySale[];
  funnel: FunnelData;
  response_time: ResponseTimeData;
  peak_hours: PeakHoursData;
  retention: RetentionData;
  filters: {
    date_from: string;
    date_to: string;
    page_id: string;
    agent_id: string;
  };
  pages: { id: number; page_name: string }[];
  agents: { id: number; name: string; role: string }[];
}

const money = new Intl.NumberFormat('en-PH', {
  style: 'currency',
  currency: 'PHP',
  maximumFractionDigits: 0,
});

function formatMoney(value: number | string) {
  return money.format(Number(value || 0));
}

function formatNumber(value: number | string) {
  return Number(value || 0).toLocaleString();
}

function emptyText(label: string) {
  return <p className="py-8 text-center text-sm text-muted-foreground">{label}</p>;
}

function formatDuration(seconds: number | null): string {
  if (seconds === null) return '—';
  if (seconds < 60) return `${seconds}s`;
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
  const hours = Math.floor(seconds / 3600);
  const mins = Math.floor((seconds % 3600) / 60);
  return `${hours}h ${mins}m`;
}

function formatHour(hour: number): string {
  const period = hour < 12 ? 'AM' : 'PM';
  const display = hour === 0 ? 12 : hour > 12 ? hour - 12 : hour;
  return `${display}${period}`;
}

export default function ShopReports({
  summary,
  page_performance,
  agent_performance,
  conversation_statuses,
  order_statuses,
  top_products,
  daily_sales,
  funnel,
  response_time,
  peak_hours,
  retention,
  filters,
  pages,
  agents,
}: Props) {
  const summaryCards = [
    {
      label: 'Sales Today',
      value: formatMoney(summary.sales_today),
      detail: `${formatNumber(summary.orders_today)} orders in selected range`,
      icon: ShoppingCart,
      color: 'text-violet-600',
    },
    {
      label: 'Shop Orders',
      value: formatNumber(summary.shop_orders),
      detail: `${formatNumber(summary.confirmed_orders)} confirmed or QA approved in range`,
      icon: ClipboardList,
      color: 'text-info',
    },
    {
      label: 'Open Conversations',
      value: formatNumber(summary.open_conversations),
      detail: 'Open, pending details, and confirmation',
      icon: Inbox,
      color: 'text-success',
    },
    {
      label: 'Webhook Events Today',
      value: formatNumber(summary.webhook_events_today),
      detail: 'Meta events received by callback',
      icon: Radio,
      color: 'text-warning',
    },
  ];

  return (
    <AppLayout>
      <Head title="Shop Reports" />

      <div className="space-y-4">
        <div className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
          <div>
            <Button asChild variant="ghost" size="sm" className="-ml-3 mb-2">
              <Link href="/shop">
                <ArrowLeft className="mr-1.5 h-4 w-4" />
                Shop
              </Link>
            </Button>
            <h1 className="text-xl font-bold tracking-tight font-display">Shop Reports</h1>
            <p className="text-muted-foreground">
              Sales, inbox movement, Page performance, and agent output
            </p>
          </div>
          <div className="flex flex-wrap gap-2">
            <Button asChild variant="outline">
              <Link href="/shop/inbox">
                <Inbox className="mr-1.5 h-4 w-4" />
                Inbox
              </Link>
            </Button>
            <Button asChild variant="outline">
              <Link href="/shop/encoder">
                <ClipboardList className="mr-1.5 h-4 w-4" />
                Encoder
              </Link>
            </Button>
            <Button asChild>
              <Link href="/shop/orders/create">
                <ShoppingCart className="mr-1.5 h-4 w-4" />
                Create Order
              </Link>
            </Button>
          </div>
        </div>

        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Filter className="h-5 w-5" />
              Filters
            </CardTitle>
            <CardDescription>Limit reports by date range, Page, or assigned agent</CardDescription>
          </CardHeader>
          <CardContent>
            <form method="get" action="/shop/reports" className="grid gap-4 md:grid-cols-5">
              <div className="space-y-2">
                <Label htmlFor="date_from">From</Label>
                <Input
                  id="date_from"
                  name="date_from"
                  type="date"
                  defaultValue={filters.date_from}
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="date_to">To</Label>
                <Input id="date_to" name="date_to" type="date" defaultValue={filters.date_to} />
              </div>
              <div className="space-y-2">
                <Label htmlFor="page_id">Page</Label>
                <select
                  id="page_id"
                  name="page_id"
                  defaultValue={filters.page_id}
                  className="h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                >
                  <option value="">All Pages</option>
                  {pages.map((page) => (
                    <option key={page.id} value={page.id}>
                      {page.page_name}
                    </option>
                  ))}
                </select>
              </div>
              <div className="space-y-2">
                <Label htmlFor="agent_id">Agent</Label>
                <select
                  id="agent_id"
                  name="agent_id"
                  defaultValue={filters.agent_id}
                  className="h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                >
                  <option value="">All Agents</option>
                  {agents.map((agent) => (
                    <option key={agent.id} value={agent.id}>
                      {agent.name}
                    </option>
                  ))}
                </select>
              </div>
              <div className="flex items-end gap-2">
                <Button type="submit" className="w-full">
                  Apply
                </Button>
              </div>
            </form>
          </CardContent>
        </Card>

        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
          {summaryCards.map((card) => {
            const Icon = card.icon;
            return (
              <Card key={card.label}>
                <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                  <CardTitle className="text-sm font-medium text-muted-foreground">
                    {card.label}
                  </CardTitle>
                  <Icon className={`h-4 w-4 ${card.color}`} />
                </CardHeader>
                <CardContent>
                  <div className="text-xl font-bold font-display">{card.value}</div>
                  <p className="mt-1 text-xs text-muted-foreground">{card.detail}</p>
                </CardContent>
              </Card>
            );
          })}
        </div>

        <div className="grid gap-6 xl:grid-cols-3">
          <Card className="xl:col-span-2">
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Store className="h-5 w-5 text-info" />
                Page Performance
              </CardTitle>
              <CardDescription>
                Connected Facebook Pages by conversations, messages, conversion, and sales
              </CardDescription>
            </CardHeader>
            <CardContent>
              {page_performance.length === 0 ? (
                emptyText('No Page performance data yet.')
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead className="border-b text-left text-xs uppercase text-muted-foreground">
                      <tr>
                        <th className="py-2 pr-3 font-medium">Page</th>
                        <th className="py-2 pr-3 text-right font-medium">Convos</th>
                        <th className="py-2 pr-3 text-right font-medium">Messages</th>
                        <th className="py-2 pr-3 text-right font-medium">Converted</th>
                        <th className="py-2 pr-3 text-right font-medium">Orders</th>
                        <th className="py-2 text-right font-medium">Sales</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y">
                      {page_performance.map((page) => (
                        <tr key={page.id}>
                          <td className="py-3 pr-3">
                            <div className="font-medium">{page.page_name}</div>
                            <div className="mt-1 flex flex-wrap gap-1">
                              <Badge variant="outline">{page.connected_status}</Badge>
                              <Badge variant="outline">{page.webhook_status}</Badge>
                            </div>
                          </td>
                          <td className="py-3 pr-3 text-right">
                            {formatNumber(page.conversations_count)}
                          </td>
                          <td className="py-3 pr-3 text-right">
                            {formatNumber(page.messages_count)}
                          </td>
                          <td className="py-3 pr-3 text-right">
                            {formatNumber(page.converted_count)}
                          </td>
                          <td className="py-3 pr-3 text-right">
                            {formatNumber(page.orders_count)}
                          </td>
                          <td className="py-3 text-right font-medium">
                            {formatMoney(page.sales_total)}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <CalendarDays className="h-5 w-5 text-violet-600" />
                Last 7 Days
              </CardTitle>
              <CardDescription>Shop order volume and sales trend</CardDescription>
            </CardHeader>
            <CardContent className="space-y-3">
              {daily_sales.map((day) => (
                <div
                  key={day.date}
                  className="grid grid-cols-[4rem_1fr_auto] items-center gap-3 text-sm"
                >
                  <span className="text-muted-foreground">{day.label}</span>
                  <div className="h-2 overflow-hidden rounded-full bg-muted">
                    <div
                      className="h-full rounded-full bg-primary"
                      style={{
                        width: `${Math.min(100, Math.max(8, Number(day.orders_count) * 16))}%`,
                      }}
                    />
                  </div>
                  <span className="font-medium">{formatMoney(day.sales_total)}</span>
                </div>
              ))}
            </CardContent>
          </Card>
        </div>

        <div className="grid gap-6 xl:grid-cols-3">
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Users className="h-5 w-5 text-success" />
                Agent Desk
              </CardTitle>
              <CardDescription>Assigned conversations and created Shop orders</CardDescription>
            </CardHeader>
            <CardContent>
              {agent_performance.length === 0 ? (
                emptyText('No agent activity yet.')
              ) : (
                <div className="space-y-3">
                  {agent_performance.map((agent) => (
                    <div key={agent.id} className="rounded-lg border p-3">
                      <div className="flex items-start justify-between gap-3">
                        <div>
                          <p className="font-medium">{agent.name}</p>
                          <p className="text-xs text-muted-foreground">{agent.role}</p>
                        </div>
                        <span className="text-sm font-semibold">
                          {formatMoney(agent.sales_total)}
                        </span>
                      </div>
                      <div className="mt-3 grid grid-cols-3 gap-2 text-center text-xs">
                        <div className="rounded-md bg-muted px-2 py-2">
                          <p className="font-semibold">
                            {formatNumber(agent.assigned_conversations)}
                          </p>
                          <p className="text-muted-foreground">Assigned</p>
                        </div>
                        <div className="rounded-md bg-muted px-2 py-2">
                          <p className="font-semibold">
                            {formatNumber(agent.converted_conversations)}
                          </p>
                          <p className="text-muted-foreground">Converted</p>
                        </div>
                        <div className="rounded-md bg-muted px-2 py-2">
                          <p className="font-semibold">{formatNumber(agent.orders_count)}</p>
                          <p className="text-muted-foreground">Orders</p>
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <BarChart3 className="h-5 w-5 text-info" />
                Status Movement
              </CardTitle>
              <CardDescription>Conversation queue and order lifecycle counts</CardDescription>
            </CardHeader>
            <CardContent className="space-y-5">
              <div className="space-y-2">
                <p className="text-sm font-medium">Conversations</p>
                {conversation_statuses.length === 0
                  ? emptyText('No conversation statuses yet.')
                  : conversation_statuses.map((row) => (
                      <div
                        key={row.status}
                        className="flex items-center justify-between rounded-lg border px-3 py-2 text-sm"
                      >
                        <span className="capitalize">{row.status.replace(/_/g, ' ')}</span>
                        <span className="font-semibold">{formatNumber(row.total)}</span>
                      </div>
                    ))}
              </div>
              <div className="space-y-2">
                <p className="text-sm font-medium">Orders</p>
                {order_statuses.length === 0
                  ? emptyText('No Shop order statuses yet.')
                  : order_statuses.map((row) => (
                      <div
                        key={row.status}
                        className="flex items-center justify-between rounded-lg border px-3 py-2 text-sm"
                      >
                        <span>{row.status}</span>
                        <span className="font-semibold">{formatNumber(row.total)}</span>
                      </div>
                    ))}
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <ShoppingCart className="h-5 w-5 text-warning" />
                Top Products
              </CardTitle>
              <CardDescription>Products sold through Shop order creation</CardDescription>
            </CardHeader>
            <CardContent>
              {top_products.length === 0 ? (
                emptyText('No Shop product sales yet.')
              ) : (
                <div className="space-y-3">
                  {top_products.map((product) => (
                    <div key={product.product_name} className="rounded-lg border p-3">
                      <div className="flex items-start justify-between gap-3">
                        <p className="font-medium">{product.product_name}</p>
                        <span className="font-semibold">{formatMoney(product.sales_total)}</span>
                      </div>
                      <div className="mt-2 flex gap-2 text-xs text-muted-foreground">
                        <span>{formatNumber(product.quantity_sold)} qty</span>
                        <span>{formatNumber(product.orders_count)} orders</span>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </CardContent>
          </Card>
        </div>

        {/* Enhanced Reports — Phase 3 M3 */}
        <div className="grid gap-6 xl:grid-cols-2">
          {/* Conversion Funnel */}
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <GitBranch className="h-5 w-5 text-violet-600" />
                Conversion Funnel
              </CardTitle>
              <CardDescription>
                Conversations → Assigned → Resolved → Orders → Confirmed → Delivered
              </CardDescription>
            </CardHeader>
            <CardContent>
              {funnel.stages.length === 0 || funnel.stages[0].count === 0 ? (
                emptyText('No funnel data for this period.')
              ) : (
                <div className="space-y-3">
                  {funnel.stages.map((stage, i) => (
                    <div key={stage.stage}>
                      <div className="mb-1 flex items-center justify-between text-sm">
                        <span className="font-medium">{stage.label}</span>
                        <span className="text-muted-foreground">
                          {formatNumber(stage.count)}{' '}
                          <span className="text-xs">({stage.percentage}%)</span>
                        </span>
                      </div>
                      <div className="h-6 overflow-hidden rounded-md bg-muted">
                        <div
                          className="h-full rounded-md bg-primary transition-all"
                          style={{
                            width: `${Math.max(2, stage.percentage)}%`,
                            opacity: 1 - i * 0.1,
                          }}
                        />
                      </div>
                    </div>
                  ))}
                  {funnel.drop_off.length > 0 && (
                    <div className="mt-4 space-y-1 border-t pt-3">
                      <p className="text-xs font-medium text-muted-foreground">Drop-off Analysis</p>
                      {funnel.drop_off.map((drop, i) => (
                        <div key={i} className="flex items-center justify-between text-xs">
                          <span className="text-muted-foreground">
                            {drop.from} → {drop.to}
                          </span>
                          <span className="font-medium text-destructive">
                            -{formatNumber(drop.lost)} ({drop.rate}%)
                          </span>
                        </div>
                      ))}
                    </div>
                  )}
                </div>
              )}
            </CardContent>
          </Card>

          {/* Response Time */}
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Clock className="h-5 w-5 text-info" />
                Response Time
              </CardTitle>
              <CardDescription>First response and resolution time analytics</CardDescription>
            </CardHeader>
            <CardContent>
              {response_time.avg_first_response_seconds === null ? (
                emptyText('No response time data yet.')
              ) : (
                <div className="space-y-4">
                  <div className="grid grid-cols-2 gap-3">
                    <div className="rounded-lg border p-3 text-center">
                      <p className="text-xs text-muted-foreground">Avg First Response</p>
                      <p className="text-lg font-bold">
                        {formatDuration(response_time.avg_first_response_seconds)}
                      </p>
                    </div>
                    <div className="rounded-lg border p-3 text-center">
                      <p className="text-xs text-muted-foreground">Median First Response</p>
                      <p className="text-lg font-bold">
                        {formatDuration(response_time.median_first_response_seconds)}
                      </p>
                    </div>
                    <div className="rounded-lg border p-3 text-center">
                      <p className="text-xs text-muted-foreground">Avg Resolution</p>
                      <p className="text-lg font-bold">
                        {formatDuration(response_time.avg_resolution_seconds)}
                      </p>
                    </div>
                    <div className="rounded-lg border p-3 text-center">
                      <p className="text-xs text-muted-foreground">Median Resolution</p>
                      <p className="text-lg font-bold">
                        {formatDuration(response_time.median_resolution_seconds)}
                      </p>
                    </div>
                  </div>

                  <div>
                    <p className="mb-2 text-xs font-medium text-muted-foreground">
                      Response Time Distribution
                    </p>
                    <div className="space-y-1.5">
                      {response_time.response_distribution.map((bucket) => {
                        const max = Math.max(
                          ...response_time.response_distribution.map((b) => b.count),
                          1
                        );
                        return (
                          <div key={bucket.bucket} className="flex items-center gap-2 text-xs">
                            <span className="w-20 text-muted-foreground">{bucket.bucket}</span>
                            <div className="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                              <div
                                className="h-full rounded-full bg-primary"
                                style={{ width: `${(bucket.count / max) * 100}%` }}
                              />
                            </div>
                            <span className="w-8 text-right font-medium">
                              {formatNumber(bucket.count)}
                            </span>
                          </div>
                        );
                      })}
                    </div>
                  </div>

                  {response_time.by_agent.length > 0 && (
                    <div>
                      <p className="mb-2 text-xs font-medium text-muted-foreground">By Agent</p>
                      <div className="space-y-1.5">
                        {response_time.by_agent.map((agent) => (
                          <div
                            key={agent.agent_id ?? 'unassigned'}
                            className="flex items-center justify-between rounded-md border px-3 py-2 text-xs"
                          >
                            <span className="font-medium">{agent.agent_name}</span>
                            <div className="flex gap-3 text-muted-foreground">
                              <span>{formatDuration(agent.avg_response_seconds)} resp</span>
                              <span>{formatDuration(agent.avg_resolution_seconds)} res</span>
                              <span className="font-medium">
                                {formatNumber(agent.conversations)} convos
                              </span>
                            </div>
                          </div>
                        ))}
                      </div>
                    </div>
                  )}
                </div>
              )}
            </CardContent>
          </Card>

          {/* Peak Hours */}
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <BarChart3 className="h-5 w-5 text-warning" />
                Peak Hours
              </CardTitle>
              <CardDescription>Inbound message volume by hour and day of week</CardDescription>
            </CardHeader>
            <CardContent>
              {peak_hours.total_messages === 0 ? (
                emptyText('No message volume data for this period.')
              ) : (
                <div className="space-y-4">
                  <div>
                    <p className="mb-2 text-xs font-medium text-muted-foreground">
                      Hourly Volume ({formatNumber(peak_hours.total_messages)} total messages)
                    </p>
                    <div className="flex items-end gap-0.5" style={{ height: '80px' }}>
                      {peak_hours.hourly.map((h) => {
                        const max = Math.max(...peak_hours.hourly.map((x) => x.count), 1);
                        return (
                          <div
                            key={h.hour}
                            className="flex-1 group relative"
                            style={{ height: '100%' }}
                          >
                            <div
                              className="absolute bottom-0 w-full rounded-t-sm bg-primary transition-all"
                              style={{
                                height: `${(h.count / max) * 100}%`,
                                minHeight: h.count > 0 ? '2px' : '0',
                              }}
                            />
                            <div className="absolute -top-6 left-1/2 -translate-x-1/2 whitespace-nowrap rounded bg-foreground px-1.5 py-0.5 text-[9px] text-background opacity-0 group-hover:opacity-100">
                              {formatHour(h.hour)}: {formatNumber(h.count)}
                            </div>
                          </div>
                        );
                      })}
                    </div>
                    <div className="mt-1 flex justify-between text-[9px] text-muted-foreground">
                      <span>12AM</span>
                      <span>6AM</span>
                      <span>12PM</span>
                      <span>6PM</span>
                      <span>11PM</span>
                    </div>
                  </div>

                  <div>
                    <p className="mb-2 text-xs font-medium text-muted-foreground">By Day of Week</p>
                    <div className="flex justify-between gap-1">
                      {peak_hours.by_day.map((d) => {
                        const max = Math.max(...peak_hours.by_day.map((x) => x.count), 1);
                        return (
                          <div key={d.day} className="flex-1 text-center">
                            <div
                              className="mx-auto mb-1 rounded-t-sm bg-primary"
                              style={{
                                height: `${(d.count / max) * 40}px`,
                                minHeight: d.count > 0 ? '4px' : '0',
                              }}
                            />
                            <p className="text-[10px] text-muted-foreground">{d.day_name}</p>
                            <p className="text-[10px] font-medium">{formatNumber(d.count)}</p>
                          </div>
                        );
                      })}
                    </div>
                  </div>

                  {peak_hours.peak_hours.length > 0 && (
                    <div>
                      <p className="mb-2 text-xs font-medium text-muted-foreground">
                        Top 5 Peak Hours
                      </p>
                      <div className="flex flex-wrap gap-1.5">
                        {peak_hours.peak_hours.map((h, i) => (
                          <Badge
                            key={h.hour}
                            variant={i === 0 ? 'destructive' : 'secondary'}
                            className="text-xs"
                          >
                            {formatHour(h.hour)} — {formatNumber(h.count)}
                          </Badge>
                        ))}
                      </div>
                    </div>
                  )}
                </div>
              )}
            </CardContent>
          </Card>

          {/* Customer Retention */}
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Repeat className="h-5 w-5 text-success" />
                Customer Retention
              </CardTitle>
              <CardDescription>New vs returning customers and repeat purchase rate</CardDescription>
            </CardHeader>
            <CardContent>
              {retention.new_customers === 0 && retention.returning_customers === 0 ? (
                emptyText('No customer retention data for this period.')
              ) : (
                <div className="space-y-4">
                  <div className="grid grid-cols-3 gap-3">
                    <div className="rounded-lg border p-3 text-center">
                      <p className="text-xs text-muted-foreground">New Customers</p>
                      <p className="text-lg font-bold text-success">
                        {formatNumber(retention.new_customers)}
                      </p>
                    </div>
                    <div className="rounded-lg border p-3 text-center">
                      <p className="text-xs text-muted-foreground">Returning</p>
                      <p className="text-lg font-bold text-info">
                        {formatNumber(retention.returning_customers)}
                      </p>
                    </div>
                    <div className="rounded-lg border p-3 text-center">
                      <p className="text-xs text-muted-foreground">Repeat Rate</p>
                      <p className="text-lg font-bold text-violet-600">
                        {retention.repeat_purchase_rate}%
                      </p>
                    </div>
                  </div>

                  <div>
                    <p className="mb-2 text-xs font-medium text-muted-foreground">
                      Orders per Customer: avg {retention.avg_orders_per_customer}
                    </p>
                    <div className="space-y-1.5">
                      {retention.distribution.map((d) => {
                        const max = Math.max(...retention.distribution.map((x) => x.customers), 1);
                        return (
                          <div key={d.order_count} className="flex items-center gap-2 text-xs">
                            <span className="w-16 text-muted-foreground">
                              {d.order_count === 5 ? '5+' : d.order_count}{' '}
                              {d.order_count === 1 ? 'order' : 'orders'}
                            </span>
                            <div className="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                              <div
                                className="h-full rounded-full bg-primary"
                                style={{ width: `${(d.customers / max) * 100}%` }}
                              />
                            </div>
                            <span className="w-10 text-right font-medium">
                              {formatNumber(d.customers)}
                            </span>
                          </div>
                        );
                      })}
                    </div>
                  </div>

                  {retention.monthly.length > 0 && (
                    <div>
                      <p className="mb-2 text-xs font-medium text-muted-foreground">
                        New vs Returning (6 months)
                      </p>
                      <div className="space-y-1.5">
                        {retention.monthly.map((m) => {
                          const total = m.new + m.returning || 1;
                          return (
                            <div key={m.month} className="flex items-center gap-2 text-xs">
                              <span className="w-14 text-muted-foreground">{m.month}</span>
                              <div className="flex h-3 flex-1 overflow-hidden rounded-full">
                                <div
                                  className="h-full bg-success"
                                  style={{ width: `${(m.new / total) * 100}%` }}
                                />
                                <div
                                  className="h-full bg-info"
                                  style={{ width: `${(m.returning / total) * 100}%` }}
                                />
                              </div>
                              <span className="w-16 text-right">
                                <span className="text-success">{formatNumber(m.new)}</span>
                                {' / '}
                                <span className="text-info">{formatNumber(m.returning)}</span>
                              </span>
                            </div>
                          );
                        })}
                      </div>
                      <div className="mt-1 flex gap-4 text-[10px] text-muted-foreground">
                        <span className="flex items-center gap-1">
                          <span className="h-2 w-2 rounded-full bg-success" /> New
                        </span>
                        <span className="flex items-center gap-1">
                          <span className="h-2 w-2 rounded-full bg-info" /> Returning
                        </span>
                      </div>
                    </div>
                  )}
                </div>
              )}
            </CardContent>
          </Card>
        </div>
      </div>
    </AppLayout>
  );
}
