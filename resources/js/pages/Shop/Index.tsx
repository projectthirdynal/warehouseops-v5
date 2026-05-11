import { Head, Link } from '@inertiajs/react';
import {
  AlertTriangle,
  ArrowRight,
  ClipboardList,
  FileSpreadsheet,
  Inbox,
  MapPinned,
  MessageSquare,
  PackageCheck,
  Phone,
  ShieldCheck,
  ShoppingCart,
  Store,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

interface ShopStats {
  connected_pages: number;
  open_conversations: number;
  orders_today: number;
  for_encoding: number;
}

interface ShopModule {
  name: string;
  status: string;
  description: string;
  items: string[];
}

interface Props {
  stats: ShopStats;
  modules: ShopModule[];
  workflow: string[];
  next_actions: string[];
}

const statCards = [
  {
    key: 'connected_pages',
    title: 'Connected Pages',
    icon: Store,
    color: 'bg-blue-500/10 text-blue-600',
  },
  {
    key: 'open_conversations',
    title: 'Open Conversations',
    icon: MessageSquare,
    color: 'bg-emerald-500/10 text-emerald-600',
  },
  {
    key: 'orders_today',
    title: 'Orders Today',
    icon: ShoppingCart,
    color: 'bg-violet-500/10 text-violet-600',
  },
  {
    key: 'for_encoding',
    title: 'For Encoding',
    icon: ClipboardList,
    color: 'bg-amber-500/10 text-amber-600',
  },
] as const;

const workQueues = [
  { name: 'Inbox', value: 0, icon: Inbox, color: 'text-blue-600' },
  { name: 'Phone Detected', value: 0, icon: Phone, color: 'text-emerald-600' },
  { name: 'Ready Orders', value: 0, icon: PackageCheck, color: 'text-violet-600' },
  { name: 'Courier Export', value: 0, icon: FileSpreadsheet, color: 'text-amber-600' },
];

const risks = [
  'Meta App Review and Page permissions',
  'Webhook subscription and event deduplication',
  'Customer phone/address data must come from messages or saved records',
  'Encoder review is required for low-confidence addresses',
];

function statusVariant(status: string) {
  if (status === 'Foundation') return 'bg-emerald-100 text-emerald-800 border-emerald-200';
  if (status === 'Schema Ready') return 'bg-blue-100 text-blue-800 border-blue-200';
  if (status === 'MVP Entry') return 'bg-blue-100 text-blue-800 border-blue-200';
  if (status === 'Webhook Ready') return 'bg-violet-100 text-violet-800 border-violet-200';
  if (status === 'Mapping Ready') return 'bg-amber-100 text-amber-800 border-amber-200';
  if (status === 'OAuth Ready') return 'bg-violet-100 text-violet-800 border-violet-200';
  if (status === 'MVP List') return 'bg-emerald-100 text-emerald-800 border-emerald-200';
  if (status === 'CSV Ready') return 'bg-amber-100 text-amber-800 border-amber-200';
  return 'bg-slate-100 text-slate-700 border-slate-200';
}

export default function ShopIndex({ stats, modules, workflow, next_actions }: Props) {
  return (
    <AppLayout>
      <Head title="Shop" />

      <div className="space-y-6">
        <div className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
          <div>
            <h1 className="text-3xl font-bold tracking-tight">Shop</h1>
            <p className="text-muted-foreground">Facebook order processing and POS workspace</p>
          </div>
          <div className="flex flex-wrap gap-2">
            <Button asChild variant="outline">
              <Link href="/shop/inbox">
                <Inbox className="mr-2 h-4 w-4" />
                Inbox
              </Link>
            </Button>
            <Button asChild variant="outline">
              <Link href="/shop/encoder">
                <ClipboardList className="mr-2 h-4 w-4" />
                Encoder
              </Link>
            </Button>
            <Button asChild variant="outline">
              <Link href="/shop/facebook/connect">
                <Store className="mr-2 h-4 w-4" />
                Connect Page
              </Link>
            </Button>
            <Button asChild>
              <Link href="/shop/orders/create">
                <ShoppingCart className="mr-2 h-4 w-4" />
                Create Order
              </Link>
            </Button>
          </div>
        </div>

        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
          {statCards.map((item) => {
            const Icon = item.icon;
            return (
              <Card key={item.key}>
                <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                  <CardTitle className="text-sm font-medium text-muted-foreground">{item.title}</CardTitle>
                  <div className={`rounded-lg p-2 ${item.color}`}>
                    <Icon className="h-4 w-4" />
                  </div>
                </CardHeader>
                <CardContent>
                  <div className="text-2xl font-bold">{stats[item.key].toLocaleString()}</div>
                  <p className="mt-1 text-xs text-muted-foreground">No live Facebook data connected yet</p>
                </CardContent>
              </Card>
            );
          })}
        </div>

        <div className="grid gap-6 xl:grid-cols-3">
          <div className="space-y-6 xl:col-span-2">
            <section className="space-y-3">
              <div className="flex items-center justify-between">
                <div>
                  <h2 className="text-lg font-semibold">Build Modules</h2>
                  <p className="text-sm text-muted-foreground">POS core first, Facebook connector next</p>
                </div>
                <Badge variant="outline">MVP</Badge>
              </div>

              <div className="grid gap-4 md:grid-cols-2">
                {modules.map((module) => (
                  <Card key={module.name}>
                    <CardHeader>
                      <div className="flex items-start justify-between gap-3">
                        <div>
                          <CardTitle className="text-base">{module.name}</CardTitle>
                          <CardDescription>{module.description}</CardDescription>
                        </div>
                        <Badge variant="outline" className={statusVariant(module.status)}>
                          {module.status}
                        </Badge>
                      </div>
                    </CardHeader>
                    <CardContent>
                      <div className="grid gap-2">
                        {module.items.map((item) => (
                          <div key={item} className="flex items-center gap-2 text-sm">
                            <ShieldCheck className="h-4 w-4 text-muted-foreground" />
                            <span>{item}</span>
                          </div>
                        ))}
                      </div>
                    </CardContent>
                  </Card>
                ))}
              </div>
            </section>

            <section className="space-y-3">
              <h2 className="text-lg font-semibold">Order Flow</h2>
              <div className="flex flex-wrap items-center gap-2 rounded-lg border bg-card p-4">
                {workflow.map((step, index) => (
                  <div key={step} className="flex items-center gap-2">
                    <Badge variant="secondary" className="px-3 py-1">
                      {step}
                    </Badge>
                    {index < workflow.length - 1 && (
                      <ArrowRight className="h-4 w-4 text-muted-foreground" />
                    )}
                  </div>
                ))}
              </div>
            </section>
          </div>

          <div className="space-y-6">
            <Card>
              <CardHeader>
                <CardTitle>Work Queues</CardTitle>
                <CardDescription>Operational counters for the Shop desk</CardDescription>
              </CardHeader>
              <CardContent className="space-y-4">
                {workQueues.map((queue) => {
                  const Icon = queue.icon;
                  return (
                    <div key={queue.name} className="flex items-center justify-between rounded-lg border px-3 py-2">
                      <div className="flex items-center gap-3">
                        <Icon className={`h-4 w-4 ${queue.color}`} />
                        <span className="text-sm font-medium">{queue.name}</span>
                      </div>
                      <span className="text-sm font-semibold">{queue.value}</span>
                    </div>
                  );
                })}
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>Implementation Guardrails</CardTitle>
                <CardDescription>Known bottlenecks before live rollout</CardDescription>
              </CardHeader>
              <CardContent className="space-y-3">
                {risks.map((risk) => (
                  <div key={risk} className="flex items-start gap-2 text-sm">
                    <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
                    <span>{risk}</span>
                  </div>
                ))}
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>Next Build Queue</CardTitle>
                <CardDescription>Suggested order after this shell</CardDescription>
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

            <Card>
              <CardHeader>
                <CardTitle>Address Mapping</CardTitle>
                <CardDescription>Encoder-safe regional classification</CardDescription>
              </CardHeader>
              <CardContent>
                <div className="flex items-center gap-3 rounded-lg border px-3 py-3">
                  <MapPinned className="h-5 w-5 text-emerald-600" />
                  <div>
                    <p className="text-sm font-medium">Reference table required</p>
                    <p className="text-xs text-muted-foreground">Province, city, barangay, region, and courier zone</p>
                  </div>
                </div>
              </CardContent>
            </Card>
          </div>
        </div>

        <div className="rounded-lg border bg-muted/30 p-4 text-sm text-muted-foreground">
          Facebook will provide Page, message, comment, and PSID data after approval. Customer phone numbers and
          addresses must be detected from messages or matched from saved customer records.
        </div>
      </div>
    </AppLayout>
  );
}
