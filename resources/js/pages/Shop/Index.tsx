import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import {
  AlertTriangle,
  ArrowRight,
  BarChart3,
  ClipboardList,
  Clock,
  FileText,
  FileSpreadsheet,
  Frown,
  Gauge,
  Inbox,
  Radio,
  MapPinned,
  MessageSquare,
  MoreHorizontal,
  PackageCheck,
  Phone,
  RefreshCw,
  Shield,
  ShieldCheck,
  ShoppingCart,
  Smile,
  Store,
  Truck,
  Users,
  Zap,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
  DropdownMenuSeparator,
  DropdownMenuLabel,
} from '@/components/ui/dropdown-menu';

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
  work_queues: {
    inbox: number;
    phone_detected: number;
    ready_orders: number;
    courier_export: number;
  };
  modules: ShopModule[];
  workflow: string[];
  next_actions: string[];
  facebook_pages: FacebookPage[];
}

interface FacebookPage {
  id: number;
  page_id: string;
  page_name: string;
  connected_status: string;
  webhook_status: string;
  last_sync_at: string | null;
}

const statCards = [
  {
    key: 'connected_pages',
    title: 'Connected Pages',
    icon: Store,
    color: 'bg-info/50/10 text-info',
  },
  {
    key: 'open_conversations',
    title: 'Open Conversations',
    icon: MessageSquare,
    color: 'bg-success/10 text-success',
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
    color: 'bg-warning/50/10 text-warning',
  },
] as const;

const risks = [
  'Meta App Review and Page permissions',
  'Webhook subscription and event deduplication',
  'Customer phone/address data must come from messages or saved records',
  'Encoder review is required for low-confidence addresses',
];

function formatDuration(seconds: number): string {
  if (seconds < 60) return `${seconds}s`;
  const m = Math.floor(seconds / 60);
  if (m < 60) return `${m}m`;
  const h = Math.floor(m / 60);
  const remM = m % 60;
  if (h < 24) return remM > 0 ? `${h}h ${remM}m` : `${h}h`;
  const d = Math.floor(h / 24);
  const remH = h % 24;
  return remH > 0 ? `${d}d ${remH}h` : `${d}d`;
}

function statusVariant(status: string) {
  if (status === 'Live') return 'bg-success/10 text-success border-success/20';
  if (status === 'Ready') return 'bg-info/10 text-info border-info/20';
  if (status === 'Next') return 'bg-muted text-muted-foreground border-border';
  if (status === 'Foundation') return 'bg-success/10 text-success border-success/20';
  if (status === 'Schema Ready') return 'bg-info/10 text-info border-info/20';
  if (status === 'MVP Entry') return 'bg-info/10 text-info border-info/20';
  if (status === 'Webhook Ready') return 'bg-violet-100 text-violet-800 border-violet-200';
  if (status === 'Mapping Ready') return 'bg-warning/10 text-warning border-warning/20';
  if (status === 'OAuth Ready') return 'bg-violet-100 text-violet-800 border-violet-200';
  if (status === 'MVP List') return 'bg-success/10 text-success border-success/20';
  if (status === 'CSV Ready') return 'bg-warning/10 text-warning border-warning/20';
  if (status === 'Subscribe Ready') return 'bg-violet-100 text-violet-800 border-violet-200';
  if (status === 'Detail Ready') return 'bg-success/10 text-success border-success/20';
  if (status === 'Correction Ready') return 'bg-warning/10 text-warning border-warning/20';
  if (status === 'Reporting Ready') return 'bg-cyan-100 text-cyan-800 border-cyan-200';
  if (status === 'CRM Ready') return 'bg-cyan-100 text-cyan-800 border-cyan-200';
  if (status === 'Automation Ready') return 'bg-cyan-100 text-cyan-800 border-cyan-200';
  return 'bg-muted text-muted-foreground border-border';
}

interface AutoAssignSettings {
  strategy: string;
  enabled: boolean;
  fallback_agent_id: number | null;
  respect_shift_hours: boolean;
  respect_queue_limits: boolean;
  strategies: Record<string, string>;
}

interface AutoAssignStats {
  today_auto: number;
  today_page_rule: number;
  today_manual: number;
  unassigned_count: number;
  eligible_agents: number;
  by_strategy: Record<string, number>;
  current_strategy: string;
}

interface CourierSyncSettings {
  auto_notify_customer: boolean;
  sync_intermediate_statuses: boolean;
  status_map: Array<{
    waybill_status: string;
    waybill_label: string;
    order_status: string;
    order_label: string;
  }>;
}

interface CourierSyncStats {
  today_synced: number;
  pending_sync: number;
  orders_with_waybills: number;
  today_delivered_via_sync: number;
  today_returned_via_sync: number;
  auto_notify_customer: boolean;
}

interface PosCacheStats {
  products_cached: boolean;
  products_count: number;
  cache_ttl: number;
  search_cache_ttl: number;
  customer_search_cache_ttl: number;
}

interface SentimentStats {
  total: number;
  positive: number;
  neutral: number;
  negative: number;
  positive_pct: number;
  neutral_pct: number;
  negative_pct: number;
  flagged_negative: number;
  auto_flagged_total: number;
  resolved_flags: number;
  recent_negative_24h: number;
  trend: { date: string; positive: number; neutral: number; negative: number }[];
}

interface SentimentSettings {
  auto_flag_enabled: boolean;
  negative_threshold: number;
  min_negative_hits: number;
  auto_unflag_enabled: boolean;
}

interface SlaStats {
  active_total: number;
  breached: number;
  warning: number;
  ok: number;
  unresponded: number;
  breach_rate: number;
  first_response: {
    count: number;
    avg_seconds: number | null;
    min_seconds: number | null;
    max_seconds: number | null;
    yesterday_avg_seconds: number | null;
  };
  resolution: {
    count: number;
    avg_seconds: number | null;
    min_seconds: number | null;
    max_seconds: number | null;
    yesterday_avg_seconds: number | null;
  };
  trend: {
    date: string;
    avg_first_response_seconds: number | null;
    responded: number;
    avg_resolution_seconds: number | null;
    resolved: number;
    created: number;
  }[];
  agent_performance: {
    agent_id: number;
    agent_name: string;
    avg_first_response_seconds: number;
    responded_count: number;
  }[];
  thresholds: Record<string, number | null>;
  warning_percent: number;
}

interface SlaSettings {
  thresholds: Record<string, number | null>;
  warning_percent: number;
  breach_notifications: boolean;
  breach_notify_channel: string;
}

export default function ShopIndex({
  stats,
  work_queues,
  modules,
  workflow,
  next_actions,
  facebook_pages,
}: Props) {
  const [assignSettings, setAssignSettings] = useState<AutoAssignSettings | null>(null);
  const [assignStats, setAssignStats] = useState<AutoAssignStats | null>(null);
  const [assignLoading, setAssignLoading] = useState(false);
  const [bulkLoading, setBulkLoading] = useState(false);
  const [syncSettings, setSyncSettings] = useState<CourierSyncSettings | null>(null);
  const [syncStats, setSyncStats] = useState<CourierSyncStats | null>(null);
  const [syncLoading, setSyncLoading] = useState(false);
  const [bulkSyncLoading, setBulkSyncLoading] = useState(false);
  const [posCache, setPosCache] = useState<PosCacheStats | null>(null);
  const [posCacheClearing, setPosCacheClearing] = useState(false);
  const [sentimentStats, setSentimentStats] = useState<SentimentStats | null>(null);
  const [sentimentSettings, setSentimentSettings] = useState<SentimentSettings | null>(null);
  const [sentimentLoading, setSentimentLoading] = useState(false);
  const [bulkSentimentLoading, setBulkSentimentLoading] = useState(false);
  const [slaStats, setSlaStats] = useState<SlaStats | null>(null);
  const [slaSettings, setSlaSettings] = useState<SlaSettings | null>(null);
  const [slaLoading, setSlaLoading] = useState(false);

  useEffect(() => {
    axios.get('/shop/auto-assign/settings').then(({ data }) => setAssignSettings(data));
    axios.get('/shop/auto-assign/stats').then(({ data }) => setAssignStats(data));
    axios.get('/shop/courier-sync/settings').then(({ data }) => setSyncSettings(data));
    axios.get('/shop/courier-sync/stats').then(({ data }) => setSyncStats(data));
    axios.get('/shop/pos/cache-stats').then(({ data }) => setPosCache(data));
    axios.get('/shop/sentiment/stats').then(({ data }) => setSentimentStats(data));
    axios.get('/shop/sentiment/settings').then(({ data }) => setSentimentSettings(data));
    axios.get('/shop/sla/stats').then(({ data }) => setSlaStats(data));
    axios.get('/shop/sla/settings').then(({ data }) => setSlaSettings(data));
  }, []);

  const refreshStats = () => {
    axios.get('/shop/auto-assign/stats').then(({ data }) => setAssignStats(data));
  };

  const refreshSyncStats = () => {
    axios.get('/shop/courier-sync/stats').then(({ data }) => setSyncStats(data));
  };

  const refreshPosCache = () => {
    axios.get('/shop/pos/cache-stats').then(({ data }) => setPosCache(data));
  };

  const refreshSentimentStats = () => {
    axios.get('/shop/sentiment/stats').then(({ data }) => setSentimentStats(data));
  };

  const refreshSlaStats = () => {
    axios.get('/shop/sla/stats').then(({ data }) => setSlaStats(data));
  };

  const saveSlaSetting = (
    key: keyof SlaSettings,
    value: boolean | number | string | Record<string, number | null>
  ) => {
    setSlaLoading(true);
    axios
      .patch('/shop/sla/settings', { [key]: value })
      .then(({ data }) => {
        setSlaSettings(data.settings);
        toast.success('SLA settings updated');
      })
      .catch(() => toast.error('Failed to update SLA settings'))
      .finally(() => setSlaLoading(false));
  };

  const saveSentimentSetting = (key: keyof SentimentSettings, value: boolean | number) => {
    setSentimentLoading(true);
    axios
      .patch('/shop/sentiment/settings', { [key]: value })
      .then(({ data }) => {
        setSentimentSettings(data.settings);
        toast.success('Sentiment settings updated');
      })
      .catch(() => toast.error('Failed to update sentiment settings'))
      .finally(() => setSentimentLoading(false));
  };

  const handleBulkSentimentAnalyze = () => {
    setBulkSentimentLoading(true);
    axios
      .post('/shop/sentiment/bulk-analyze')
      .then(({ data }) => {
        toast.success(data.message || 'Bulk analysis complete');
        refreshSentimentStats();
      })
      .catch(() => toast.error('Bulk analysis failed'))
      .finally(() => setBulkSentimentLoading(false));
  };

  const clearPosCache = () => {
    setPosCacheClearing(true);
    axios
      .post('/shop/pos/cache-clear')
      .then(({ data }) => {
        toast.success(data.message || 'POS cache cleared');
        refreshPosCache();
      })
      .catch(() => toast.error('Failed to clear cache'))
      .finally(() => setPosCacheClearing(false));
  };

  const saveSyncSetting = (key: keyof CourierSyncSettings, value: boolean) => {
    setSyncLoading(true);
    axios
      .patch('/shop/courier-sync/settings', { [key]: value })
      .then(({ data }) => {
        setSyncSettings(data.settings);
        toast.success('Courier sync settings updated');
      })
      .catch(() => toast.error('Failed to update sync settings'))
      .finally(() => setSyncLoading(false));
  };

  const handleBulkSync = () => {
    setBulkSyncLoading(true);
    axios
      .post('/shop/courier-sync/bulk')
      .then(({ data }) => {
        toast.success(
          `Synced ${data.synced} waybill(s)${data.skipped > 0 ? `, ${data.skipped} skipped` : ''}`
        );
        refreshSyncStats();
      })
      .catch(() => toast.error('Bulk courier sync failed'))
      .finally(() => setBulkSyncLoading(false));
  };

  const saveSetting = (key: keyof AutoAssignSettings, value: string | boolean | number | null) => {
    if (!assignSettings) return;
    setAssignLoading(true);
    axios
      .patch('/shop/auto-assign/settings', { [key]: value })
      .then(({ data }) => {
        setAssignSettings(data.settings);
        toast.success('Auto-assignment settings updated');
      })
      .catch(() => toast.error('Failed to update settings'))
      .finally(() => setAssignLoading(false));
  };

  const handleBulkAssign = () => {
    setBulkLoading(true);
    axios
      .post('/shop/auto-assign/bulk')
      .then(({ data }) => {
        toast.success(
          `Assigned ${data.assigned} conversation(s)${data.skipped > 0 ? `, ${data.skipped} skipped` : ''}`
        );
        refreshStats();
      })
      .catch(() => toast.error('Bulk auto-assign failed'))
      .finally(() => setBulkLoading(false));
  };
  const workQueues = [
    {
      name: 'Inbox',
      value: work_queues.inbox,
      icon: Inbox,
      color: 'text-info',
      href: '/shop/inbox',
    },
    {
      name: 'Phone Detected',
      value: work_queues.phone_detected,
      icon: Phone,
      color: 'text-success',
      href: '/shop/inbox',
    },
    {
      name: 'Ready Orders',
      value: work_queues.ready_orders,
      icon: PackageCheck,
      color: 'text-violet-600',
      href: '/shop/encoder',
    },
    {
      name: 'Courier Export',
      value: work_queues.courier_export,
      icon: FileSpreadsheet,
      color: 'text-warning',
      href: '/shop/encoder',
    },
  ];

  return (
    <AppLayout>
      <Head title="Shop" />

      <div className="space-y-4">
        <div className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
          <div>
            <h1 className="text-xl font-bold tracking-tight font-display">Shop</h1>
            <p className="text-muted-foreground">Facebook order processing and POS workspace</p>
          </div>
          <div className="flex items-center gap-2">
            <Button asChild size="sm">
              <Link href="/shop/pos">
                <ShoppingCart className="mr-1.5 h-4 w-4" />
                POS
              </Link>
            </Button>
            <Button asChild variant="outline" size="sm">
              <Link href="/shop/inbox">
                <Inbox className="mr-1.5 h-4 w-4" />
                Inbox
              </Link>
            </Button>
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="outline" size="sm">
                  <MoreHorizontal className="h-4 w-4" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end" className="w-48">
                <DropdownMenuLabel className="text-xs text-muted-foreground">
                  Shop Tools
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem asChild>
                  <Link href="/shop/encoder" className="cursor-pointer">
                    <ClipboardList className="mr-1.5 h-4 w-4" /> Encoder
                  </Link>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                  <Link href="/shop/templates" className="cursor-pointer">
                    <FileText className="mr-1.5 h-4 w-4" /> Templates
                  </Link>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                  <Link href="/shop/reports" className="cursor-pointer">
                    <BarChart3 className="mr-1.5 h-4 w-4" /> Reports
                  </Link>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                  <Link href="/shop/meta-readiness" className="cursor-pointer">
                    <Shield className="mr-1.5 h-4 w-4" /> Meta Readiness
                  </Link>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                  <Link href="/shop/webhooks" className="cursor-pointer">
                    <Radio className="mr-1.5 h-4 w-4" /> Webhooks
                  </Link>
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem asChild>
                  <a href="/shop/facebook/connect" className="cursor-pointer">
                    <Store className="mr-1.5 h-4 w-4" /> Connect Page
                  </a>
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </div>
        </div>

        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
          {statCards.map((item) => {
            const Icon = item.icon;
            return (
              <Card key={item.key}>
                <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                  <CardTitle className="text-sm font-medium text-muted-foreground">
                    {item.title}
                  </CardTitle>
                  <div className={`rounded-lg p-2 ${item.color}`}>
                    <Icon className="h-4 w-4" />
                  </div>
                </CardHeader>
                <CardContent>
                  <div className="text-xl font-bold font-display">
                    {stats[item.key].toLocaleString()}
                  </div>
                  <p className="mt-1 text-xs text-muted-foreground">Live Shop operational count</p>
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
                  <p className="text-sm text-muted-foreground">
                    POS core first, Facebook connector next
                  </p>
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
                    <Link
                      key={queue.name}
                      href={queue.href}
                      className="flex items-center justify-between rounded-lg border px-3 py-2 transition-colors hover:bg-accent/30"
                    >
                      <div className="flex items-center gap-3">
                        <Icon className={`h-4 w-4 ${queue.color}`} />
                        <span className="text-sm font-medium">{queue.name}</span>
                      </div>
                      <span className="text-sm font-semibold">{queue.value.toLocaleString()}</span>
                    </Link>
                  );
                })}
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>Connected Pages</CardTitle>
                <CardDescription>Subscribe Pages after OAuth sync</CardDescription>
              </CardHeader>
              <CardContent className="space-y-3">
                {facebook_pages.length === 0 ? (
                  <p className="text-sm text-muted-foreground">No Facebook Pages connected yet.</p>
                ) : (
                  facebook_pages.map((page) => (
                    <div key={page.id} className="rounded-lg border p-3">
                      <div className="flex items-start justify-between gap-3">
                        <div>
                          <p className="text-sm font-medium">{page.page_name}</p>
                          <p className="text-xs text-muted-foreground">
                            {page.connected_status} / {page.webhook_status}
                          </p>
                        </div>
                        <div className="flex flex-wrap justify-end gap-2">
                          <Button asChild size="sm" variant="outline">
                            <Link
                              href={`/shop/facebook/pages/${page.id}/check`}
                              method="post"
                              as="button"
                            >
                              Check
                            </Link>
                          </Button>
                          <Button
                            asChild
                            size="sm"
                            variant={page.webhook_status === 'subscribed' ? 'outline' : 'default'}
                          >
                            <Link
                              href={`/shop/facebook/pages/${page.id}/subscribe`}
                              method="post"
                              as="button"
                            >
                              {page.webhook_status === 'subscribed' ? 'Resubscribe' : 'Subscribe'}
                            </Link>
                          </Button>
                        </div>
                      </div>
                    </div>
                  ))
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Zap className="h-4 w-4 text-violet-600" />
                  Auto-Assignment Engine
                </CardTitle>
                <CardDescription>Strategy, agent eligibility, and bulk assignment</CardDescription>
              </CardHeader>
              <CardContent className="space-y-4">
                {assignSettings ? (
                  <>
                    <div className="flex items-center justify-between">
                      <Label htmlFor="aa-enabled" className="text-sm">
                        Enabled
                      </Label>
                      <Switch
                        id="aa-enabled"
                        checked={assignSettings.enabled}
                        onCheckedChange={(v) => saveSetting('enabled', v)}
                        disabled={assignLoading}
                      />
                    </div>

                    <div className="space-y-1.5">
                      <Label className="text-sm">Strategy</Label>
                      <Select
                        value={assignSettings.strategy}
                        onValueChange={(v) => saveSetting('strategy', v)}
                        disabled={assignLoading}
                      >
                        <SelectTrigger className="w-full">
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          {Object.entries(assignSettings.strategies).map(([key, label]) => (
                            <SelectItem key={key} value={key}>
                              {label}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </div>

                    <div className="flex items-center justify-between">
                      <Label htmlFor="aa-shift" className="text-sm">
                        Respect shift hours
                      </Label>
                      <Switch
                        id="aa-shift"
                        checked={assignSettings.respect_shift_hours}
                        onCheckedChange={(v) => saveSetting('respect_shift_hours', v)}
                        disabled={assignLoading}
                      />
                    </div>

                    <div className="flex items-center justify-between">
                      <Label htmlFor="aa-queue" className="text-sm">
                        Respect queue limits
                      </Label>
                      <Switch
                        id="aa-queue"
                        checked={assignSettings.respect_queue_limits}
                        onCheckedChange={(v) => saveSetting('respect_queue_limits', v)}
                        disabled={assignLoading}
                      />
                    </div>

                    {assignStats && (
                      <div className="grid grid-cols-2 gap-2 pt-2 border-t">
                        <div className="rounded-lg border p-2">
                          <p className="text-xs text-muted-foreground">Unassigned</p>
                          <p className="text-lg font-bold font-display">
                            {assignStats.unassigned_count}
                          </p>
                        </div>
                        <div className="rounded-lg border p-2">
                          <p className="text-xs text-muted-foreground">Eligible agents</p>
                          <p className="text-lg font-bold font-display flex items-center gap-1">
                            <Users className="h-3.5 w-3.5" />
                            {assignStats.eligible_agents}
                          </p>
                        </div>
                        <div className="rounded-lg border p-2">
                          <p className="text-xs text-muted-foreground">Auto-assigned today</p>
                          <p className="text-lg font-bold font-display text-success">
                            {assignStats.today_auto}
                          </p>
                        </div>
                        <div className="rounded-lg border p-2">
                          <p className="text-xs text-muted-foreground">Page-rule today</p>
                          <p className="text-lg font-bold font-display text-info">
                            {assignStats.today_page_rule}
                          </p>
                        </div>
                      </div>
                    )}

                    <Button
                      className="w-full"
                      variant="default"
                      onClick={handleBulkAssign}
                      disabled={bulkLoading || !assignStats?.unassigned_count}
                    >
                      <Zap className="mr-1.5 h-4 w-4" />
                      {bulkLoading
                        ? 'Assigning...'
                        : `Bulk Auto-Assign${assignStats ? ` (${assignStats.unassigned_count})` : ''}`}
                    </Button>
                  </>
                ) : (
                  <p className="text-sm text-muted-foreground">Loading settings...</p>
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Truck className="h-4 w-4 text-cyan-600" />
                  Order-Courier Status Sync
                </CardTitle>
                <CardDescription>Waybill status auto-updates linked orders</CardDescription>
              </CardHeader>
              <CardContent className="space-y-4">
                {syncSettings ? (
                  <>
                    <div className="flex items-center justify-between">
                      <Label htmlFor="cs-notify" className="text-sm">
                        Auto-notify customer
                      </Label>
                      <Switch
                        id="cs-notify"
                        checked={syncSettings.auto_notify_customer}
                        onCheckedChange={(v) => saveSyncSetting('auto_notify_customer', v)}
                        disabled={syncLoading}
                      />
                    </div>

                    <div className="flex items-center justify-between">
                      <Label htmlFor="cs-intermediate" className="text-sm">
                        Sync intermediate statuses
                      </Label>
                      <Switch
                        id="cs-intermediate"
                        checked={syncSettings.sync_intermediate_statuses}
                        onCheckedChange={(v) => saveSyncSetting('sync_intermediate_statuses', v)}
                        disabled={syncLoading}
                      />
                    </div>

                    {syncStats && (
                      <div className="grid grid-cols-2 gap-2 pt-2 border-t">
                        <div className="rounded-lg border p-2">
                          <p className="text-xs text-muted-foreground">Pending sync</p>
                          <p className="text-lg font-bold font-display">{syncStats.pending_sync}</p>
                        </div>
                        <div className="rounded-lg border p-2">
                          <p className="text-xs text-muted-foreground">Orders w/ waybills</p>
                          <p className="text-lg font-bold font-display">
                            {syncStats.orders_with_waybills}
                          </p>
                        </div>
                        <div className="rounded-lg border p-2">
                          <p className="text-xs text-muted-foreground">Synced today</p>
                          <p className="text-lg font-bold font-display text-success">
                            {syncStats.today_synced}
                          </p>
                        </div>
                        <div className="rounded-lg border p-2">
                          <p className="text-xs text-muted-foreground">Delivered via sync</p>
                          <p className="text-lg font-bold font-display text-success">
                            {syncStats.today_delivered_via_sync}
                          </p>
                        </div>
                      </div>
                    )}

                    <details className="text-xs">
                      <summary className="cursor-pointer text-muted-foreground hover:text-foreground">
                        Status mapping ({syncSettings.status_map.length} statuses)
                      </summary>
                      <div className="mt-2 space-y-1 rounded-lg border p-2">
                        {syncSettings.status_map.map((m) => (
                          <div key={m.waybill_status} className="flex items-center justify-between">
                            <span className="text-muted-foreground">{m.waybill_label}</span>
                            <span className="font-medium">{m.order_label}</span>
                          </div>
                        ))}
                      </div>
                    </details>

                    <Button
                      className="w-full"
                      variant="default"
                      onClick={handleBulkSync}
                      disabled={bulkSyncLoading || !syncStats?.pending_sync}
                    >
                      <Truck className="mr-1.5 h-4 w-4" />
                      {bulkSyncLoading
                        ? 'Syncing...'
                        : `Bulk Sync${syncStats ? ` (${syncStats.pending_sync})` : ''}`}
                    </Button>
                  </>
                ) : (
                  <p className="text-sm text-muted-foreground">Loading settings...</p>
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Gauge className="h-5 w-5 text-primary" />
                  POS Checkout Performance
                </CardTitle>
                <CardDescription>Product list & customer lookup cache</CardDescription>
              </CardHeader>
              <CardContent className="space-y-4">
                {posCache ? (
                  <>
                    <div className="grid grid-cols-2 gap-3">
                      <div className="rounded-lg border p-3">
                        <p className="text-xs text-muted-foreground">Product Cache</p>
                        <p className="text-lg font-bold">
                          {posCache.products_cached ? (
                            <span className="text-success">{posCache.products_count} items</span>
                          ) : (
                            <span className="text-muted-foreground">Cold</span>
                          )}
                        </p>
                      </div>
                      <div className="rounded-lg border p-3">
                        <p className="text-xs text-muted-foreground">Cache TTL</p>
                        <p className="text-lg font-bold">{posCache.cache_ttl}s</p>
                      </div>
                      <div className="rounded-lg border p-3">
                        <p className="text-xs text-muted-foreground">Search Cache TTL</p>
                        <p className="text-lg font-bold">{posCache.search_cache_ttl}s</p>
                      </div>
                      <div className="rounded-lg border p-3">
                        <p className="text-xs text-muted-foreground">Customer Search TTL</p>
                        <p className="text-lg font-bold">{posCache.customer_search_cache_ttl}s</p>
                      </div>
                    </div>
                    <div className="flex items-center justify-between rounded-lg border bg-muted/30 p-3">
                      <div className="flex items-center gap-2">
                        <Zap className="h-4 w-4 text-success" />
                        <span className="text-sm">
                          {posCache.products_cached
                            ? 'Product list served from cache'
                            : 'Product list will cache on next load'}
                        </span>
                      </div>
                      <Button
                        size="sm"
                        variant="outline"
                        onClick={clearPosCache}
                        disabled={posCacheClearing}
                      >
                        <RefreshCw
                          className={`mr-1.5 h-3.5 w-3.5 ${posCacheClearing ? 'animate-spin' : ''}`}
                        />
                        Clear Cache
                      </Button>
                    </div>
                  </>
                ) : (
                  <p className="text-sm text-muted-foreground">Loading cache stats...</p>
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Frown className="h-5 w-5 text-destructive" />
                  Sentiment Analysis
                </CardTitle>
                <CardDescription>Auto-detect negative sentiment & flag for review</CardDescription>
              </CardHeader>
              <CardContent className="space-y-4">
                {sentimentStats && sentimentSettings ? (
                  <>
                    <div className="grid grid-cols-3 gap-3">
                      <div className="rounded-lg border p-3 text-center">
                        <Smile className="mx-auto mb-1 h-5 w-5 text-success" />
                        <p className="text-xs text-muted-foreground">Positive</p>
                        <p className="text-lg font-bold text-success">{sentimentStats.positive}</p>
                        <p className="text-xs text-muted-foreground">
                          {sentimentStats.positive_pct}%
                        </p>
                      </div>
                      <div className="rounded-lg border p-3 text-center">
                        <div className="mx-auto mb-1 h-5 w-5 rounded-full bg-muted-foreground/20" />
                        <p className="text-xs text-muted-foreground">Neutral</p>
                        <p className="text-lg font-bold">{sentimentStats.neutral}</p>
                        <p className="text-xs text-muted-foreground">
                          {sentimentStats.neutral_pct}%
                        </p>
                      </div>
                      <div className="rounded-lg border p-3 text-center">
                        <Frown className="mx-auto mb-1 h-5 w-5 text-destructive" />
                        <p className="text-xs text-muted-foreground">Negative</p>
                        <p className="text-lg font-bold text-destructive">
                          {sentimentStats.negative}
                        </p>
                        <p className="text-xs text-muted-foreground">
                          {sentimentStats.negative_pct}%
                        </p>
                      </div>
                    </div>

                    <div className="grid grid-cols-2 gap-3">
                      <div className="rounded-lg border p-3">
                        <p className="text-xs text-muted-foreground">Flagged for review</p>
                        <p className="text-lg font-bold text-destructive">
                          {sentimentStats.flagged_negative}
                        </p>
                      </div>
                      <div className="rounded-lg border p-3">
                        <p className="text-xs text-muted-foreground">Negative (24h)</p>
                        <p className="text-lg font-bold">{sentimentStats.recent_negative_24h}</p>
                      </div>
                      <div className="rounded-lg border p-3">
                        <p className="text-xs text-muted-foreground">Auto-flagged total</p>
                        <p className="text-lg font-bold">{sentimentStats.auto_flagged_total}</p>
                      </div>
                      <div className="rounded-lg border p-3">
                        <p className="text-xs text-muted-foreground">Resolved flags</p>
                        <p className="text-lg font-bold text-success">
                          {sentimentStats.resolved_flags}
                        </p>
                      </div>
                    </div>

                    {/* 7-day trend mini bar */}
                    {sentimentStats.trend.length > 0 && (
                      <div className="rounded-lg border p-3">
                        <p className="mb-2 text-xs text-muted-foreground">7-day sentiment trend</p>
                        <div className="flex items-end gap-1">
                          {sentimentStats.trend.map((day) => {
                            const max = Math.max(day.positive + day.neutral + day.negative, 1);
                            return (
                              <div
                                key={day.date}
                                className="flex flex-1 flex-col items-center gap-0.5"
                                title={`${day.date}: ${day.positive}+ ${day.neutral}= ${day.negative}-`}
                              >
                                <div className="flex h-16 w-full flex-col justify-end overflow-hidden rounded-sm">
                                  {day.negative > 0 && (
                                    <div
                                      className="bg-destructive/60"
                                      style={{
                                        height: `${(day.negative / max) * 100}%`,
                                      }}
                                    />
                                  )}
                                  {day.neutral > 0 && (
                                    <div
                                      className="bg-muted-foreground/30"
                                      style={{
                                        height: `${(day.neutral / max) * 100}%`,
                                      }}
                                    />
                                  )}
                                  {day.positive > 0 && (
                                    <div
                                      className="bg-success/60"
                                      style={{
                                        height: `${(day.positive / max) * 100}%`,
                                      }}
                                    />
                                  )}
                                </div>
                                <span className="text-[10px] text-muted-foreground">
                                  {day.date.slice(5)}
                                </span>
                              </div>
                            );
                          })}
                        </div>
                      </div>
                    )}

                    {/* Settings */}
                    <div className="space-y-3 rounded-lg border bg-muted/30 p-3">
                      <div className="flex items-center justify-between">
                        <Label className="text-sm">Auto-flag negative sentiment</Label>
                        <Switch
                          checked={sentimentSettings.auto_flag_enabled}
                          onCheckedChange={(v) => saveSentimentSetting('auto_flag_enabled', v)}
                          disabled={sentimentLoading}
                        />
                      </div>
                      <div className="flex items-center justify-between">
                        <Label className="text-sm">Auto-unflag when sentiment improves</Label>
                        <Switch
                          checked={sentimentSettings.auto_unflag_enabled}
                          onCheckedChange={(v) => saveSentimentSetting('auto_unflag_enabled', v)}
                          disabled={sentimentLoading}
                        />
                      </div>
                      <div className="flex items-center justify-between">
                        <Label className="text-sm">
                          Min negative keywords: {sentimentSettings.min_negative_hits}
                        </Label>
                        <input
                          type="range"
                          min={1}
                          max={10}
                          value={sentimentSettings.min_negative_hits}
                          onChange={(e) =>
                            saveSentimentSetting('min_negative_hits', parseInt(e.target.value))
                          }
                          disabled={sentimentLoading}
                          className="w-24"
                        />
                      </div>
                      <div className="flex items-center justify-between">
                        <Label className="text-sm">
                          Negative threshold: {sentimentSettings.negative_threshold}
                        </Label>
                        <input
                          type="range"
                          min={-0.5}
                          max={0}
                          step={0.05}
                          value={sentimentSettings.negative_threshold}
                          onChange={(e) =>
                            saveSentimentSetting('negative_threshold', parseFloat(e.target.value))
                          }
                          disabled={sentimentLoading}
                          className="w-24"
                        />
                      </div>
                    </div>

                    <Button
                      className="w-full"
                      variant="default"
                      onClick={handleBulkSentimentAnalyze}
                      disabled={bulkSentimentLoading}
                    >
                      <RefreshCw
                        className={`mr-1.5 h-4 w-4 ${bulkSentimentLoading ? 'animate-spin' : ''}`}
                      />
                      {bulkSentimentLoading ? 'Analyzing...' : 'Bulk Analyze Conversations'}
                    </Button>
                  </>
                ) : (
                  <p className="text-sm text-muted-foreground">Loading sentiment data...</p>
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Clock className="h-5 w-5 text-amber-600" />
                  Conversation SLA
                </CardTitle>
                <CardDescription>
                  First-response time, resolution time, breach alerts
                </CardDescription>
              </CardHeader>
              <CardContent className="space-y-4">
                {slaStats && slaSettings ? (
                  <>
                    {/* SLA Status Distribution */}
                    <div className="grid grid-cols-4 gap-2">
                      <div className="rounded-lg border bg-success/5 p-2 text-center">
                        <p className="text-lg font-bold text-success">{slaStats.ok}</p>
                        <p className="text-[10px] text-muted-foreground">OK</p>
                      </div>
                      <div className="rounded-lg border bg-amber-50 p-2 text-center">
                        <p className="text-lg font-bold text-amber-600">{slaStats.warning}</p>
                        <p className="text-[10px] text-muted-foreground">Warning</p>
                      </div>
                      <div className="rounded-lg border bg-destructive/5 p-2 text-center">
                        <p className="text-lg font-bold text-destructive">{slaStats.breached}</p>
                        <p className="text-[10px] text-muted-foreground">Breached</p>
                      </div>
                      <div className="rounded-lg border bg-muted/30 p-2 text-center">
                        <p className="text-lg font-bold text-muted-foreground">
                          {slaStats.unresponded}
                        </p>
                        <p className="text-[10px] text-muted-foreground">Unresponded</p>
                      </div>
                    </div>

                    {/* First Response & Resolution Stats */}
                    <div className="grid grid-cols-2 gap-3">
                      <div className="rounded-lg border p-3 space-y-1">
                        <p className="text-xs font-medium text-muted-foreground">
                          Avg First Response
                        </p>
                        <p className="text-xl font-bold tabular-nums">
                          {slaStats.first_response.avg_seconds
                            ? formatDuration(slaStats.first_response.avg_seconds)
                            : '—'}
                        </p>
                        <p className="text-[10px] text-muted-foreground">
                          {slaStats.first_response.count} responded today
                        </p>
                        {slaStats.first_response.yesterday_avg_seconds !== null && (
                          <p className="text-[10px] text-muted-foreground">
                            Yesterday:{' '}
                            {formatDuration(slaStats.first_response.yesterday_avg_seconds)}
                          </p>
                        )}
                      </div>
                      <div className="rounded-lg border p-3 space-y-1">
                        <p className="text-xs font-medium text-muted-foreground">Avg Resolution</p>
                        <p className="text-xl font-bold tabular-nums">
                          {slaStats.resolution.avg_seconds
                            ? formatDuration(slaStats.resolution.avg_seconds)
                            : '—'}
                        </p>
                        <p className="text-[10px] text-muted-foreground">
                          {slaStats.resolution.count} resolved today
                        </p>
                        {slaStats.resolution.yesterday_avg_seconds !== null && (
                          <p className="text-[10px] text-muted-foreground">
                            Yesterday: {formatDuration(slaStats.resolution.yesterday_avg_seconds)}
                          </p>
                        )}
                      </div>
                    </div>

                    {/* Breach Rate */}
                    <div className="flex items-center justify-between rounded-lg border bg-muted/30 px-3 py-2">
                      <span className="text-sm font-medium">Breach Rate</span>
                      <div className="flex items-center gap-2">
                        <span
                          className={`text-lg font-bold tabular-nums ${slaStats.breach_rate > 20 ? 'text-destructive' : slaStats.breach_rate > 10 ? 'text-amber-600' : 'text-success'}`}
                        >
                          {slaStats.breach_rate}%
                        </span>
                        <span className="text-xs text-muted-foreground">
                          ({slaStats.breached}/{slaStats.active_total})
                        </span>
                      </div>
                    </div>

                    {/* 7-day Trend */}
                    {slaStats.trend.length > 0 && (
                      <div className="space-y-1.5">
                        <p className="text-xs font-medium text-muted-foreground">
                          7-Day Response Time Trend
                        </p>
                        <div className="flex items-end gap-1 h-20">
                          {slaStats.trend.map((day) => {
                            const maxFr = Math.max(
                              ...slaStats.trend.map((d) => d.avg_first_response_seconds ?? 0),
                              1
                            );
                            const fr = day.avg_first_response_seconds ?? 0;
                            const heightPct = fr > 0 ? (fr / maxFr) * 100 : 0;
                            return (
                              <div
                                key={day.date}
                                className="flex flex-1 flex-col items-center gap-0.5"
                                title={`${day.date}: ${fr > 0 ? formatDuration(fr) : 'no data'}, ${day.responded} responded, ${day.resolved} resolved`}
                              >
                                <div className="flex h-16 w-full flex-col justify-end overflow-hidden rounded-sm">
                                  <div
                                    className="bg-amber-500/60"
                                    style={{ height: `${heightPct}%` }}
                                  />
                                </div>
                                <span className="text-[10px] text-muted-foreground">
                                  {day.date.slice(5)}
                                </span>
                              </div>
                            );
                          })}
                        </div>
                      </div>
                    )}

                    {/* Agent Performance */}
                    {slaStats.agent_performance.length > 0 && (
                      <div className="space-y-1.5">
                        <p className="text-xs font-medium text-muted-foreground">
                          Top Agent First-Response (today)
                        </p>
                        <div className="space-y-1">
                          {slaStats.agent_performance.slice(0, 5).map((agent, i) => (
                            <div
                              key={agent.agent_id}
                              className="flex items-center justify-between rounded-md border bg-background px-2.5 py-1.5 text-xs"
                            >
                              <div className="flex items-center gap-2">
                                <span className="flex h-5 w-5 items-center justify-center rounded-full bg-primary/10 text-[10px] font-semibold text-primary">
                                  {i + 1}
                                </span>
                                <span className="font-medium">{agent.agent_name}</span>
                              </div>
                              <div className="flex items-center gap-2">
                                <span className="tabular-nums text-muted-foreground">
                                  {agent.responded_count} resp
                                </span>
                                <span className="tabular-nums font-medium">
                                  {formatDuration(agent.avg_first_response_seconds)}
                                </span>
                              </div>
                            </div>
                          ))}
                        </div>
                      </div>
                    )}

                    {/* Settings */}
                    <div className="space-y-3 rounded-lg border bg-muted/30 p-3">
                      <p className="text-xs font-semibold text-muted-foreground">
                        SLA Thresholds (minutes)
                      </p>
                      <div className="grid grid-cols-2 gap-2">
                        {Object.entries(slaSettings.thresholds).map(([status, minutes]) => (
                          <div key={status} className="flex items-center justify-between">
                            <Label className="text-xs capitalize">{status}</Label>
                            <input
                              type="number"
                              min={1}
                              max={10080}
                              value={minutes ?? ''}
                              disabled={minutes === null || slaLoading}
                              onChange={(e) => {
                                const newThresholds = {
                                  ...slaSettings.thresholds,
                                  [status]: parseInt(e.target.value) || null,
                                };
                                saveSlaSetting('thresholds', newThresholds);
                              }}
                              className="w-20 rounded-md border bg-background px-2 py-1 text-xs tabular-nums"
                            />
                          </div>
                        ))}
                      </div>
                      <div className="flex items-center justify-between">
                        <Label className="text-xs">
                          Warning threshold: {slaSettings.warning_percent}%
                        </Label>
                        <input
                          type="range"
                          min={50}
                          max={99}
                          value={slaSettings.warning_percent}
                          onChange={(e) =>
                            saveSlaSetting('warning_percent', parseInt(e.target.value))
                          }
                          disabled={slaLoading}
                          className="w-24"
                        />
                      </div>
                      <div className="flex items-center justify-between">
                        <Label className="text-xs">Breach notifications</Label>
                        <Switch
                          checked={slaSettings.breach_notifications}
                          onCheckedChange={(v) => saveSlaSetting('breach_notifications', v)}
                          disabled={slaLoading}
                        />
                      </div>
                    </div>

                    <Button
                      className="w-full"
                      variant="outline"
                      onClick={refreshSlaStats}
                      disabled={slaLoading}
                    >
                      <RefreshCw className={`mr-1.5 h-4 w-4 ${slaLoading ? 'animate-spin' : ''}`} />
                      Refresh SLA Stats
                    </Button>
                  </>
                ) : (
                  <p className="text-sm text-muted-foreground">Loading SLA data...</p>
                )}
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
                    <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-warning" />
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
                  <MapPinned className="h-5 w-5 text-success" />
                  <div>
                    <p className="text-sm font-medium">Reference table required</p>
                    <p className="text-xs text-muted-foreground">
                      Province, city, barangay, region, and courier zone
                    </p>
                  </div>
                </div>
              </CardContent>
            </Card>
          </div>
        </div>

        <div className="rounded-lg border bg-muted/30 p-4 text-sm text-muted-foreground">
          Facebook will provide Page, message, comment, and PSID data after approval. Customer phone
          numbers and addresses must be detected from messages or matched from saved customer
          records.
        </div>
      </div>
    </AppLayout>
  );
}
