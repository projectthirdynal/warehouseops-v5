import { Head, Link } from '@inertiajs/react';
import {
  ArrowLeft,
  BarChart3,
  CalendarDays,
  ClipboardList,
  Filter,
  Inbox,
  Radio,
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

interface Props {
  summary: Summary;
  page_performance: PagePerformance[];
  agent_performance: AgentPerformance[];
  conversation_statuses: StatusTotal[];
  order_statuses: StatusTotal[];
  top_products: TopProduct[];
  daily_sales: DailySale[];
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

export default function ShopReports({
  summary,
  page_performance,
  agent_performance,
  conversation_statuses,
  order_statuses,
  top_products,
  daily_sales,
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
      </div>
    </AppLayout>
  );
}
