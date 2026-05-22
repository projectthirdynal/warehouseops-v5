import { useEffect, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import {
  AlertTriangle,
  ArrowRight,
  Banknote,
  BarChart3,
  ClipboardList,
  Clock,
  CreditCard,
  FileSpreadsheet,
  Inbox,
  MessageSquare,
  PackageCheck,
  Radio,
  Settings,
  ShieldCheck,
  ShoppingCart,
  Store,
  TrendingUp,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

interface ShopStats {
  connected_pages: number;
  open_conversations: number;
  orders_today: number;
  sales_today: string | number;
  paid_today: string | number;
  cod_receivable: string | number;
  avg_order_value: string | number;
  customers: number;
  for_encoding: number;
}

interface Workspace {
  name: string;
  href: string;
  status: string;
  description: string;
  items: string[];
}

interface FacebookPage {
  id: number;
  page_id: string;
  page_name: string;
  connected_status: string;
  webhook_status: string;
  last_sync_at: string | null;
  orders_today: number;
  sales_today: string | number;
}

interface ChannelMetric {
  channel: string;
  label: string;
  orders_today: number;
  sales_today: string | number;
  open_orders: number;
}

interface PipelineMetric {
  key: string;
  label: string;
  count: number;
}

interface PaymentMetric {
  method: string;
  orders_count: number;
  sales_total: string | number;
}

interface RecentOrder {
  id: number;
  order_number: string;
  status: string;
  source_channel: string;
  receiver_name: string;
  receiver_phone: string;
  total_amount: string | number;
  cod_amount: string | number;
  payment_method: string | null;
  payment_status: string | null;
  created_at: string | null;
  items_summary: string;
  facebook_page?: { id: number; page_name: string } | null;
  customer?: { id: number; name: string; risk_level: string; is_blacklisted: boolean } | null;
}

interface RecentConversation {
  id: number;
  status: string;
  customer_name: string;
  phone: string | null;
  page_name: string | null;
  assigned_agent: string | null;
  last_message_preview: string | null;
  last_message_at: string | null;
  unread_count: number;
}

interface DashboardAlert {
  level: string;
  title: string;
  detail: string;
  href: string;
}

interface Props {
  stats: ShopStats;
  work_queues: {
    inbox: number;
    pending_details: number;
    ready_orders: number;
    courier_export: number;
    pending_orders: number;
    unpaid_orders: number;
    failed_webhooks: number;
  };
  workspaces: Workspace[];
  workflow: string[];
  next_actions: string[];
  facebook_pages: FacebookPage[];
  channel_metrics: ChannelMetric[];
  fulfillment_pipeline: PipelineMetric[];
  payment_mix: PaymentMetric[];
  recent_orders: RecentOrder[];
  recent_conversations: RecentConversation[];
  dashboard_alerts: DashboardAlert[];
}

function money(value: string | number | null | undefined, digits = 0) {
  return new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    maximumFractionDigits: digits,
  }).format(Number(value ?? 0));
}

function label(value: string | null | undefined) {
  if (!value) return 'None';
  return value.replace(/_/g, ' ').replace(/\b\w/g, (character) => character.toUpperCase());
}

function timeAgo(value: string | null) {
  if (!value) return 'No activity';

  const diff = Date.now() - new Date(value).getTime();
  const minutes = Math.max(0, Math.floor(diff / 60000));

  if (minutes < 1) return 'Just now';
  if (minutes < 60) return `${minutes}m ago`;

  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `${hours}h ago`;

  return `${Math.floor(hours / 24)}d ago`;
}

function syncTime(value: Date) {
  return value.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}

function statusClass(status: string) {
  if (['DELIVERED', 'QA_APPROVED', 'CONFIRMED'].includes(status)) return 'bg-emerald-600 text-white';
  if (['RETURNED', 'CANCELLED', 'QA_REJECTED'].includes(status)) return 'bg-red-600 text-white';
  if (['DISPATCHED', 'PROCESSING'].includes(status)) return 'bg-blue-600 text-white';
  return 'bg-cyan-700 text-white';
}

function workspaceStatusClass(status: string) {
  if (status === 'Live') return 'bg-emerald-100 text-emerald-800 border-emerald-200';
  if (status === 'Ready') return 'bg-blue-100 text-blue-800 border-blue-200';
  if (status === 'Automation Ready') return 'bg-cyan-100 text-cyan-800 border-cyan-200';
  return 'bg-slate-100 text-slate-700 border-slate-200';
}

function alertClass(level: string) {
  if (level === 'danger') return 'border-red-200 bg-red-50 text-red-900';
  if (level === 'warning') return 'border-amber-200 bg-amber-50 text-amber-900';
  return 'border-blue-200 bg-blue-50 text-blue-900';
}

export default function ShopIndex({
  stats,
  work_queues,
  workspaces,
  workflow,
  next_actions,
  facebook_pages,
  channel_metrics,
  fulfillment_pipeline,
  payment_mix,
  recent_orders,
  recent_conversations,
  dashboard_alerts,
}: Props) {
  const [lastSyncAt, setLastSyncAt] = useState(() => new Date());

  useEffect(() => {
    const interval = window.setInterval(() => {
      if (document.visibilityState !== 'visible') {
        return;
      }

      router.reload({
        only: [
          'stats',
          'work_queues',
          'facebook_pages',
          'channel_metrics',
          'fulfillment_pipeline',
          'payment_mix',
          'recent_orders',
          'recent_conversations',
          'dashboard_alerts',
        ],
        onSuccess: () => setLastSyncAt(new Date()),
      });
    }, 15000);

    return () => window.clearInterval(interval);
  }, []);

  const statCards = [
    { title: 'Sales Today', value: money(stats.sales_today), icon: BarChart3, detail: `${stats.orders_today.toLocaleString()} orders` },
    { title: 'Paid Today', value: money(stats.paid_today), icon: CreditCard, detail: `${money(stats.cod_receivable)} COD receivable` },
    { title: 'Open CRM', value: stats.open_conversations.toLocaleString(), icon: MessageSquare, detail: `${work_queues.pending_details.toLocaleString()} missing details` },
    { title: 'For Encoding', value: stats.for_encoding.toLocaleString(), icon: PackageCheck, detail: `${work_queues.unpaid_orders.toLocaleString()} unpaid active orders` },
  ];

  const queues = [
    { name: 'CRM Inbox', value: work_queues.inbox, href: '/shop/inbox', icon: Inbox },
    { name: 'Missing Details', value: work_queues.pending_details, href: '/shop/inbox?status=pending_details', icon: ClipboardList },
    { name: 'Pending Orders', value: work_queues.pending_orders, href: '/shop/orders?status=PENDING', icon: Clock },
    { name: 'Ready to Encode', value: work_queues.ready_orders, href: '/shop/encoder', icon: PackageCheck },
    { name: 'Unpaid Active', value: work_queues.unpaid_orders, href: '/shop/orders', icon: Banknote },
    { name: 'Exports Today', value: work_queues.courier_export, href: '/shop/encoder', icon: FileSpreadsheet },
  ];

  return (
    <AppLayout>
      <Head title="Shop POS / CRM Dashboard" />

      <div className="-m-4 min-h-[calc(100vh-4rem)] bg-slate-100 p-4 lg:-m-6 lg:p-6">
        <div className="mb-4 flex flex-col gap-4 border-b border-slate-200 pb-4 lg:flex-row lg:items-end lg:justify-between">
          <div>
            <h1 className="text-2xl font-bold tracking-tight">Shop POS / CRM Dashboard</h1>
            <p className="text-sm text-muted-foreground">Live command center for order capture, customer conversations, payments, and fulfillment handoff.</p>
          </div>
          <div className="flex flex-wrap gap-2">
            <Badge variant="outline" className="h-10 rounded-md border-emerald-200 bg-emerald-50 px-3 text-emerald-800">
              Live sync {syncTime(lastSyncAt)}
            </Badge>
            <Button asChild>
              <Link href="/shop/pos">
                <ShoppingCart className="mr-2 h-4 w-4" />
                POS Register
              </Link>
            </Button>
            <Button asChild variant="outline">
              <Link href="/shop/inbox">
                <Inbox className="mr-2 h-4 w-4" />
                CRM Inbox
              </Link>
            </Button>
            <Button asChild variant="outline">
              <Link href="/shop/orders">
                <ClipboardList className="mr-2 h-4 w-4" />
                Orders
              </Link>
            </Button>
            <Button asChild variant="outline">
              <Link href="/shop/reports">
                <TrendingUp className="mr-2 h-4 w-4" />
                Reports
              </Link>
            </Button>
          </div>
        </div>

        {dashboard_alerts.length > 0 && (
          <div className="mb-4 grid gap-3 xl:grid-cols-3">
            {dashboard_alerts.map((alert) => (
              <Link key={alert.title} href={alert.href} className={`rounded-md border p-3 transition-opacity hover:opacity-80 ${alertClass(alert.level)}`}>
                <div className="flex items-start gap-2">
                  <AlertTriangle className="mt-0.5 h-4 w-4" />
                  <div>
                    <p className="text-sm font-semibold">{alert.title}</p>
                    <p className="mt-1 text-xs opacity-80">{alert.detail}</p>
                  </div>
                </div>
              </Link>
            ))}
          </div>
        )}

        <div className="mb-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
          {statCards.map((item) => {
            const Icon = item.icon;
            return (
              <Card key={item.title} className="rounded-md">
                <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                  <CardTitle className="text-sm font-medium text-muted-foreground">{item.title}</CardTitle>
                  <Icon className="h-4 w-4 text-muted-foreground" />
                </CardHeader>
                <CardContent>
                  <div className="text-2xl font-bold">{item.value}</div>
                  <p className="mt-1 text-xs text-muted-foreground">{item.detail}</p>
                </CardContent>
              </Card>
            );
          })}
        </div>

        <div className="grid gap-4 xl:grid-cols-[1fr_380px]">
          <div className="space-y-4">
            <div className="grid gap-4 lg:grid-cols-2">
              <Card className="rounded-md">
                <CardHeader className="pb-3">
                  <CardTitle>Sales Channels</CardTitle>
                  <CardDescription>Today by order source</CardDescription>
                </CardHeader>
                <CardContent className="space-y-3">
                  {channel_metrics.map((channel) => (
                    <Link key={channel.channel} href={`/shop/orders?source_channel=${channel.channel}`} className="grid grid-cols-[1fr_90px_90px] items-center gap-3 rounded-md border p-3 transition-colors hover:bg-accent/30">
                      <div>
                        <p className="text-sm font-medium">{channel.label}</p>
                        <p className="text-xs text-muted-foreground">{channel.open_orders.toLocaleString()} open orders</p>
                      </div>
                      <div className="text-right">
                        <p className="text-sm font-semibold">{channel.orders_today.toLocaleString()}</p>
                        <p className="text-xs text-muted-foreground">orders</p>
                      </div>
                      <div className="text-right">
                        <p className="text-sm font-semibold">{money(channel.sales_today)}</p>
                        <p className="text-xs text-muted-foreground">sales</p>
                      </div>
                    </Link>
                  ))}
                </CardContent>
              </Card>

              <Card className="rounded-md">
                <CardHeader className="pb-3">
                  <CardTitle>Fulfillment Pipeline</CardTitle>
                  <CardDescription>Current Shop order stages</CardDescription>
                </CardHeader>
                <CardContent className="space-y-3">
                  {fulfillment_pipeline.map((stage) => (
                    <Link key={stage.key} href="/shop/orders" className="flex items-center justify-between rounded-md border p-3 transition-colors hover:bg-accent/30">
                      <span className="text-sm font-medium">{stage.label}</span>
                      <span className="text-xl font-bold">{stage.count.toLocaleString()}</span>
                    </Link>
                  ))}
                </CardContent>
              </Card>
            </div>

            <div className="grid gap-4 lg:grid-cols-[1.2fr_0.8fr]">
              <Card className="rounded-md">
                <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-3">
                  <div>
                    <CardTitle>Recent Orders</CardTitle>
                    <CardDescription>Latest POS/CRM captures</CardDescription>
                  </div>
                  <Button asChild size="sm" variant="outline">
                    <Link href="/shop/orders">View all</Link>
                  </Button>
                </CardHeader>
                <CardContent className="space-y-2">
                  {recent_orders.length === 0 ? (
                    <p className="py-8 text-center text-sm text-muted-foreground">No Shop orders yet.</p>
                  ) : (
                    recent_orders.map((order) => (
                      <Link key={order.id} href={`/shop/orders/${order.id}`} className="grid gap-2 rounded-md border p-3 transition-colors hover:bg-accent/30 md:grid-cols-[1fr_120px_110px] md:items-center">
                        <div className="min-w-0">
                          <div className="flex flex-wrap items-center gap-2">
                            <p className="font-medium">{order.order_number}</p>
                            <Badge className={statusClass(order.status)}>{label(order.status)}</Badge>
                            <Badge variant="outline">{label(order.source_channel)}</Badge>
                          </div>
                          <p className="mt-1 truncate text-sm text-muted-foreground">{order.receiver_name} - {order.items_summary || 'No items'}</p>
                          <p className="mt-1 text-xs text-muted-foreground">{order.receiver_phone} - {timeAgo(order.created_at)}</p>
                        </div>
                        <div className="text-sm md:text-right">
                          <p className="font-semibold">{money(order.total_amount)}</p>
                          <p className="text-xs text-muted-foreground">{order.payment_method ?? 'COD'} / {order.payment_status ?? 'UNPAID'}</p>
                        </div>
                        <div className="text-sm md:text-right">
                          <p className="font-semibold text-cyan-700">{money(order.cod_amount)}</p>
                          <p className="text-xs text-muted-foreground">COD due</p>
                        </div>
                      </Link>
                    ))
                  )}
                </CardContent>
              </Card>

              <Card className="rounded-md">
                <CardHeader className="pb-3">
                  <CardTitle>Payment Mix</CardTitle>
                  <CardDescription>Today by payment method</CardDescription>
                </CardHeader>
                <CardContent className="space-y-3">
                  {payment_mix.length === 0 ? (
                    <p className="py-8 text-center text-sm text-muted-foreground">No payments today.</p>
                  ) : (
                    payment_mix.map((payment) => (
                      <div key={payment.method} className="flex items-center justify-between rounded-md border p-3">
                        <div>
                          <p className="text-sm font-medium">{label(payment.method)}</p>
                          <p className="text-xs text-muted-foreground">{payment.orders_count.toLocaleString()} orders</p>
                        </div>
                        <p className="font-semibold">{money(payment.sales_total)}</p>
                      </div>
                    ))
                  )}
                </CardContent>
              </Card>
            </div>

            <Card className="rounded-md">
              <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-3">
                <div>
                  <CardTitle>CRM Activity</CardTitle>
                  <CardDescription>Newest customer conversations</CardDescription>
                </div>
                <Button asChild size="sm" variant="outline">
                  <Link href="/shop/inbox">Open inbox</Link>
                </Button>
              </CardHeader>
              <CardContent className="grid gap-2 lg:grid-cols-2">
                {recent_conversations.length === 0 ? (
                  <p className="py-8 text-center text-sm text-muted-foreground lg:col-span-2">No CRM conversations yet.</p>
                ) : (
                  recent_conversations.map((conversation) => (
                    <Link key={conversation.id} href={`/shop/inbox/${conversation.id}`} className="rounded-md border p-3 transition-colors hover:bg-accent/30">
                      <div className="flex items-start justify-between gap-3">
                        <div className="min-w-0">
                          <p className="truncate text-sm font-medium">{conversation.customer_name}</p>
                          <p className="text-xs text-muted-foreground">{conversation.page_name ?? 'No Page'} - {conversation.assigned_agent ?? 'Unassigned'}</p>
                        </div>
                        <Badge variant={conversation.unread_count > 0 ? 'default' : 'outline'}>{conversation.unread_count}</Badge>
                      </div>
                      <p className="mt-2 line-clamp-2 text-sm text-muted-foreground">{conversation.last_message_preview ?? 'No message preview'}</p>
                      <p className="mt-2 text-xs text-muted-foreground">{label(conversation.status)} - {timeAgo(conversation.last_message_at)}</p>
                    </Link>
                  ))
                )}
              </CardContent>
            </Card>

            <section className="space-y-3">
              <h2 className="text-lg font-semibold">Operating Flow</h2>
              <div className="flex flex-wrap items-center gap-2 rounded-md border bg-white p-4">
                {workflow.map((step, index) => (
                  <div key={step} className="flex items-center gap-2">
                    <Badge variant="secondary" className="px-3 py-1">{step}</Badge>
                    {index < workflow.length - 1 && <ArrowRight className="h-4 w-4 text-muted-foreground" />}
                  </div>
                ))}
              </div>
            </section>
          </div>

          <div className="space-y-4">
            <Card className="rounded-md">
              <CardHeader className="pb-3">
                <CardTitle>Live Queues</CardTitle>
                <CardDescription>Daily work that needs attention</CardDescription>
              </CardHeader>
              <CardContent className="grid gap-2">
                {queues.map((queue) => {
                  const Icon = queue.icon;
                  return (
                    <Link key={queue.name} href={queue.href} className="flex items-center justify-between rounded-md border px-3 py-2 transition-colors hover:bg-accent/30">
                      <span className="flex items-center gap-2 text-sm font-medium">
                        <Icon className="h-4 w-4 text-muted-foreground" />
                        {queue.name}
                      </span>
                      <span className="text-sm font-semibold">{queue.value.toLocaleString()}</span>
                    </Link>
                  );
                })}
              </CardContent>
            </Card>

            <Card className="rounded-md">
              <CardHeader className="pb-3">
                <CardTitle>Connected Sales Channels</CardTitle>
                <CardDescription>Facebook Pages used by CRM and capture</CardDescription>
              </CardHeader>
              <CardContent className="space-y-3">
                {facebook_pages.length === 0 ? (
                  <div className="space-y-3">
                    <p className="text-sm text-muted-foreground">No Facebook Pages connected yet.</p>
                    <Button asChild size="sm">
                      <a href="/shop/facebook/connect">
                        <Store className="mr-2 h-4 w-4" />
                        Connect Page
                      </a>
                    </Button>
                  </div>
                ) : (
                  facebook_pages.map((page) => (
                    <div key={page.id} className="rounded-md border p-3">
                      <div className="flex items-start justify-between gap-3">
                        <div>
                          <p className="text-sm font-medium">{page.page_name}</p>
                          <p className="text-xs text-muted-foreground">{page.connected_status} / {page.webhook_status}</p>
                        </div>
                        <Badge variant={page.webhook_status === 'subscribed' ? 'default' : 'outline'}>{page.webhook_status}</Badge>
                      </div>
                      <div className="mt-3 grid grid-cols-2 gap-2 text-xs">
                        <span>Orders: <strong>{page.orders_today}</strong></span>
                        <span>Sales: <strong>{money(page.sales_today)}</strong></span>
                      </div>
                    </div>
                  ))
                )}
                <div className="flex flex-wrap gap-2 pt-1">
                  <Button asChild size="sm" variant="outline">
                    <Link href="/shop/webhooks">
                      <Radio className="mr-2 h-4 w-4" />
                      Webhooks
                    </Link>
                  </Button>
                  <Button asChild size="sm" variant="outline">
                    <Link href="/shop/meta-readiness">
                      <Settings className="mr-2 h-4 w-4" />
                      Meta Setup
                    </Link>
                  </Button>
                </div>
              </CardContent>
            </Card>

            <Card className="rounded-md">
              <CardHeader className="pb-3">
                <CardTitle>Workspaces</CardTitle>
                <CardDescription>Operational areas</CardDescription>
              </CardHeader>
              <CardContent className="space-y-2">
                {workspaces.map((workspace) => (
                  <Link key={workspace.name} href={workspace.href} className="block rounded-md border p-3 transition-colors hover:bg-accent/30">
                    <div className="flex items-start justify-between gap-3">
                      <div>
                        <p className="text-sm font-medium">{workspace.name}</p>
                        <p className="mt-1 line-clamp-2 text-xs text-muted-foreground">{workspace.description}</p>
                      </div>
                      <Badge variant="outline" className={workspaceStatusClass(workspace.status)}>
                        {workspace.status}
                      </Badge>
                    </div>
                    <div className="mt-2 flex flex-wrap gap-1">
                      {workspace.items.slice(0, 3).map((item) => (
                        <span key={item} className="inline-flex items-center gap-1 rounded bg-slate-100 px-2 py-1 text-xs">
                          <ShieldCheck className="h-3 w-3" />
                          {item}
                        </span>
                      ))}
                    </div>
                  </Link>
                ))}
              </CardContent>
            </Card>

            <Card className="rounded-md">
              <CardHeader className="pb-3">
                <CardTitle>Next Alignment Work</CardTitle>
                <CardDescription>Remaining rollout items</CardDescription>
              </CardHeader>
              <CardContent className="space-y-3">
                {next_actions.map((action, index) => (
                  <div key={action} className="flex gap-3 text-sm">
                    <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary">
                      {index + 1}
                    </span>
                    <span>{action}</span>
                  </div>
                ))}
              </CardContent>
            </Card>
          </div>
        </div>
      </div>
    </AppLayout>
  );
}
