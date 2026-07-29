import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';
import {
  Truck,
  CheckCircle2,
  XCircle,
  Users,
  TrendingUp,
  TrendingDown,
  Clock,
  AlertCircle,
  QrCode,
  ClipboardCheck,
  Recycle,
  BarChart3,
  ArrowRight,
  FileText,
  DollarSign,
  Package,
  Headphones,
  ShieldAlert,
  UserCog,
  RefreshCw,
  GripVertical,
  Eye,
  EyeOff,
  LayoutGrid,
  RotateCcw,
  AlertTriangle,
  Bell,
  PackageX,
  FileWarning,
  TruckIcon,
  Wallet,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Switch } from '@/components/ui/switch';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import type {
  DashboardStats,
  DashboardHourlyItem,
  DashboardActivity,
  DashboardTrends,
  PageProps,
} from '@/types';

interface WidgetItem {
  key: string;
  label: string;
  description: string;
  category: string;
  is_visible: boolean;
  sort_order: number;
  settings: Record<string, unknown>;
}

interface WidgetConfigData {
  widgets: WidgetItem[];
  visible_widgets: WidgetItem[];
  hidden_widgets: WidgetItem[];
  dashboard: string;
}

interface AlertItem {
  type: 'low_stock' | 'sla_breach' | 'failed_import' | 'undelivered';
  severity: 'critical' | 'warning';
  title: string;
  description: string;
  href: string;
  created_at: string;
}

interface RevenuePeriod {
  value: number;
  trend: number | null;
}

interface ConversionTrendDay {
  date: string;
  label: string;
  leads: number;
  sales: number;
  conversion: number;
}

interface TopProduct {
  id: number;
  name: string;
  sku: string;
  revenue: number;
  order_count: number;
}

interface RevenueSummaryData {
  periods: {
    today: RevenuePeriod;
    week: RevenuePeriod;
    month: RevenuePeriod;
  };
  conversion_trend: ConversionTrendDay[];
  top_products: TopProduct[];
}

interface Props {
  stats: DashboardStats;
  recentActivity: DashboardActivity[];
  hourlyActivity: DashboardHourlyItem[];
  trends: DashboardTrends;
  role?: string;
  widgetConfig?: WidgetConfigData;
  alerts?: AlertItem[];
  revenueSummary?: RevenueSummaryData;
}

function StatCard({
  title,
  value,
  description,
  icon: Icon,
  trend,
  variant = 'default',
  href,
}: {
  title: string;
  value: string | number;
  description?: string;
  icon: React.ComponentType<{ className?: string }>;
  trend?: { value: number | null; label: string };
  variant?: 'default' | 'success' | 'warning' | 'danger';
  href?: string;
}) {
  const iconColors = {
    default: 'text-primary',
    success: 'text-success',
    warning: 'text-warning',
    danger: 'text-destructive',
  };
  const bgColors = {
    default: 'bg-primary/10',
    success: 'bg-success/10',
    warning: 'bg-warning/10',
    danger: 'bg-destructive/10',
  };

  const content = (
    <Card
      className={`transition-all ${href ? 'hover:shadow-md hover:border-primary/50 cursor-pointer' : ''}`}
    >
      <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
        <CardTitle className="text-sm font-medium text-muted-foreground">{title}</CardTitle>
        <div className={`rounded-lg p-2 ${bgColors[variant]}`}>
          <Icon className={`h-4 w-4 ${iconColors[variant]}`} />
        </div>
      </CardHeader>
      <CardContent>
        <div className="text-xl font-bold font-display tabular-nums">{value}</div>
        {description && <p className="text-xs text-muted-foreground mt-1">{description}</p>}
        {trend && trend.value !== null && (
          <div className="flex items-center gap-1 mt-2">
            {trend.value >= 0 ? (
              <TrendingUp className="h-4 w-4 text-success" />
            ) : (
              <TrendingDown className="h-4 w-4 text-destructive" />
            )}
            <span
              className={`text-xs font-medium tabular-nums ${trend.value >= 0 ? 'text-success' : 'text-destructive'}`}
            >
              {trend.value >= 0 ? '+' : ''}
              {trend.value}%
            </span>
            <span className="text-xs text-muted-foreground">{trend.label}</span>
          </div>
        )}
      </CardContent>
    </Card>
  );

  if (href) return <Link href={href}>{content}</Link>;
  return content;
}

const ACTIVITY_ICONS: Record<string, React.ComponentType<{ className?: string }>> = {
  Waybill: Truck,
  Lead: Users,
  QC: ClipboardCheck,
  System: BarChart3,
};

const ACTIVITY_COLORS: Record<string, string> = {
  Waybill: 'bg-info',
  Lead: 'bg-success',
  QC: 'bg-primary',
  System: 'bg-muted-foreground',
};

export default function Dashboard({
  stats,
  recentActivity,
  hourlyActivity,
  trends,
  role,
  widgetConfig,
  alerts: initialAlerts,
  revenueSummary: initialRevenue,
}: Props) {
  const [liveStats, setLiveStats] = useState(stats);
  const [liveActivity, setLiveActivity] = useState(recentActivity);
  const [liveHourly, setLiveHourly] = useState(hourlyActivity);
  const [liveTrends, setLiveTrends] = useState(trends);
  const [lastUpdated, setLastUpdated] = useState<string | null>(null);
  const [refreshing, setRefreshing] = useState(false);

  // ── Widget customization state ──
  const [widgets, setWidgets] = useState<WidgetItem[]>(widgetConfig?.widgets ?? []);
  const [showCustomize, setShowCustomize] = useState(false);
  const [draggedIdx, setDraggedIdx] = useState<number | null>(null);
  const [dragOverIdx, setDragOverIdx] = useState<number | null>(null);
  const [savingWidgets, setSavingWidgets] = useState(false);

  // ── Alerts state ──
  const [liveAlerts, setLiveAlerts] = useState<AlertItem[]>(initialAlerts ?? []);

  // ── Revenue summary state ──
  const [liveRevenue, setLiveRevenue] = useState<RevenueSummaryData | null>(initialRevenue ?? null);

  const widgetVisible = (key: string): boolean =>
    widgets.find((w) => w.key === key)?.is_visible ?? true;

  const s = liveStats ??
    stats ?? {
      total_waybills: 0,
      pending_dispatch: 0,
      in_transit: 0,
      delivered_today: 0,
      returned_today: 0,
      total_leads: 0,
      new_leads: 0,
      sales_today: 0,
      conversion_rate: 0,
      qc_pending: 0,
      agents_online: 0,
    };

  const page = usePage<PageProps>();
  const effectiveRole = role ?? page.props.auth?.user?.role ?? 'agent';

  const refreshStats = useCallback(async () => {
    setRefreshing(true);
    try {
      const res = await fetch('/api/dashboard/stats', {
        headers: { Accept: 'application/json' },
      });
      if (res.ok) {
        const data = await res.json();
        setLiveStats(data.stats);
        setLiveActivity(data.recentActivity);
        setLiveHourly(data.hourlyActivity);
        setLiveTrends(data.trends);
        setLastUpdated(data.updated_at);
      }

      // Fetch alerts in parallel (non-blocking)
      fetch('/api/dashboard/alerts', { headers: { Accept: 'application/json' } })
        .then((r) => (r.ok ? r.json() : null))
        .then((d) => {
          if (d) setLiveAlerts(d.alerts);
        })
        .catch(() => {});

      // Fetch revenue summary in parallel (non-blocking)
      fetch('/api/dashboard/revenue-summary', { headers: { Accept: 'application/json' } })
        .then((r) => (r.ok ? r.json() : null))
        .then((d) => {
          if (d) setLiveRevenue(d.revenue);
        })
        .catch(() => {});
    } catch {
      // silently fail — keep existing data
    } finally {
      setRefreshing(false);
    }
  }, []);

  useEffect(() => {
    const interval = setInterval(refreshStats, 30_000);
    return () => clearInterval(interval);
  }, [refreshStats]);

  // ── Widget config handlers ──
  const saveWidgetConfig = useCallback(async (newWidgets: WidgetItem[]) => {
    setSavingWidgets(true);
    try {
      const res = await fetch('/api/dashboard/widgets', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-TOKEN':
            document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
        },
        body: JSON.stringify({
          dashboard: 'main',
          widgets: newWidgets.map((w, i) => ({
            key: w.key,
            is_visible: w.is_visible,
            sort_order: i + 1,
            settings: w.settings,
          })),
        }),
      });
      if (res.ok) {
        const data = await res.json();
        setWidgets(data.widgets);
        toast.success('Dashboard layout saved.');
      } else {
        toast.error('Failed to save layout.');
      }
    } catch {
      toast.error('Failed to save layout.');
    } finally {
      setSavingWidgets(false);
    }
  }, []);

  const resetWidgetConfig = useCallback(async () => {
    setSavingWidgets(true);
    try {
      const res = await fetch('/api/dashboard/widgets/reset?dashboard=main', {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'X-CSRF-TOKEN':
            document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
        },
      });
      if (res.ok) {
        const data = await res.json();
        setWidgets(data.widgets);
        toast.success('Dashboard layout reset to defaults.');
      } else {
        toast.error('Failed to reset layout.');
      }
    } catch {
      toast.error('Failed to reset layout.');
    } finally {
      setSavingWidgets(false);
    }
  }, []);

  function toggleWidgetVisibility(key: string) {
    setWidgets((prev) =>
      prev.map((w) => (w.key === key ? { ...w, is_visible: !w.is_visible } : w))
    );
  }

  function handleDragStart(idx: number) {
    setDraggedIdx(idx);
  }

  function handleDragOver(e: React.DragEvent, idx: number) {
    e.preventDefault();
    setDragOverIdx(idx);
  }

  function handleDrop(idx: number) {
    if (draggedIdx === null || draggedIdx === idx) {
      setDraggedIdx(null);
      setDragOverIdx(null);
      return;
    }
    setWidgets((prev) => {
      const updated = [...prev];
      const [moved] = updated.splice(draggedIdx, 1);
      updated.splice(idx, 0, moved);
      return updated;
    });
    setDraggedIdx(null);
    setDragOverIdx(null);
  }

  function handleSaveLayout() {
    saveWidgetConfig(widgets);
    setShowCustomize(false);
  }

  function handleResetLayout() {
    resetWidgetConfig();
  }

  // ── Role-based stat card configs ──
  type StatCardConfig = {
    title: string;
    value: string | number;
    icon: React.ComponentType<{ className?: string }>;
    variant?: 'default' | 'success' | 'warning' | 'danger';
    description?: string;
    href?: string;
    trend?: { value: number | null; label: string };
  };

  const FINANCE_ROLES = ['finance', 'accounting'];
  const WAREHOUSE_ROLES = ['warehouse'];

  const isFinance = FINANCE_ROLES.includes(effectiveRole);
  const isWarehouse = WAREHOUSE_ROLES.includes(effectiveRole);
  const isAgent = effectiveRole === 'agent';
  const isTeamLeader = effectiveRole === 'teamleader';
  const isClaims = effectiveRole === 'claims_officer';
  const isChecker = effectiveRole === 'checker';
  const isEncoder = effectiveRole === 'encoder';

  // ── Stat cards per role ──
  let statCards1: StatCardConfig[] = [];
  let statCards2: StatCardConfig[] = [];

  if (isAgent) {
    statCards1 = [
      {
        title: 'New Leads',
        value: s.new_leads,
        icon: Users,
        variant: 'success',
        description: 'Unassigned',
        href: '/leads',
      },
      {
        title: 'Sales Today',
        value: s.sales_today,
        icon: TrendingUp,
        variant: 'success',
        trend: { value: liveTrends?.sales ?? null, label: 'vs yesterday' },
      },
      {
        title: 'Conversion Rate',
        value: `${s.conversion_rate}%`,
        icon: BarChart3,
        description: 'Leads to sales',
      },
      {
        title: 'QC Pending',
        value: s.qc_pending,
        icon: AlertCircle,
        variant: s.qc_pending > 10 ? 'danger' : 'warning',
        description: 'Awaiting review',
        href: '/qc',
      },
    ];
    statCards2 = [
      {
        title: 'My Open Tickets',
        value: s.my_tickets ?? 0,
        icon: Headphones,
        variant: 'warning',
        description: 'Assigned to me',
        href: '/tickets',
      },
      { title: 'Total Leads', value: s.total_leads, icon: Users, href: '/leads' },
      {
        title: 'Agents Online',
        value: s.agents_online,
        icon: Users,
        variant: 'success',
        description: 'Active in last hour',
      },
      { title: 'Total Waybills', value: s.total_waybills, icon: Truck, href: '/waybills' },
    ];
  } else if (isFinance) {
    statCards1 = [
      {
        title: 'Revenue Today',
        value: `₱${(s.revenue_today ?? 0).toLocaleString()}`,
        icon: DollarSign,
        variant: 'success',
      },
      {
        title: 'Total Revenue',
        value: `₱${(s.total_revenue ?? 0).toLocaleString()}`,
        icon: TrendingUp,
        variant: 'success',
      },
      {
        title: 'Invoices Unpaid',
        value: s.invoices_unpaid ?? 0,
        icon: FileText,
        variant: 'warning',
        description: 'Sent / Partial',
        href: '/finance/invoices',
      },
      {
        title: 'Invoices Overdue',
        value: s.invoices_overdue ?? 0,
        icon: AlertCircle,
        variant: 'danger',
        href: '/finance/invoices',
      },
    ];
    statCards2 = [
      {
        title: 'Sales Today',
        value: s.sales_today,
        icon: TrendingUp,
        variant: 'success',
        trend: { value: liveTrends?.sales ?? null, label: 'vs yesterday' },
      },
      { title: 'Conversion Rate', value: `${s.conversion_rate}%`, icon: BarChart3 },
      { title: 'Total Leads', value: s.total_leads, icon: Users, href: '/leads' },
      { title: 'Total Waybills', value: s.total_waybills, icon: Truck, href: '/waybills' },
    ];
  } else if (isWarehouse) {
    statCards1 = [
      {
        title: 'Pending Dispatch',
        value: s.pending_dispatch,
        icon: Clock,
        variant: 'warning',
        description: 'Awaiting scan',
        href: '/waybills',
      },
      {
        title: 'In Transit',
        value: s.in_transit,
        icon: Truck,
        description: 'With courier',
        href: '/waybills',
      },
      {
        title: 'Delivered Today',
        value: s.delivered_today,
        icon: CheckCircle2,
        variant: 'success',
        trend: { value: liveTrends?.delivered ?? null, label: 'vs yesterday' },
      },
      { title: 'Returns Today', value: s.returned_today, icon: XCircle, variant: 'danger' },
    ];
    statCards2 = [
      {
        title: 'Low Stock Items',
        value: s.low_stock_count ?? 0,
        icon: Package,
        variant: (s.low_stock_count ?? 0) > 0 ? 'warning' : 'default',
        description: 'At or below reorder point',
        href: '/inventory',
      },
      {
        title: 'Total Products',
        value: s.total_products ?? 0,
        icon: BarChart3,
        href: '/inventory',
      },
      { title: 'Total Waybills', value: s.total_waybills, icon: Truck, href: '/waybills' },
      { title: 'Total Leads', value: s.total_leads, icon: Users, href: '/leads' },
    ];
  } else if (isClaims) {
    statCards1 = [
      { title: 'Returns Today', value: s.returned_today, icon: XCircle, variant: 'danger' },
      {
        title: 'Claims Pending',
        value: s.claims_pending ?? 0,
        icon: ShieldAlert,
        variant: 'warning',
        description: 'Awaiting resolution',
        href: '/waybills/claims',
      },
      {
        title: 'Beyond SLA',
        value: s.beyond_sla_count ?? 0,
        icon: AlertCircle,
        variant: 'danger',
        href: '/waybills/claims/beyond-sla',
      },
      {
        title: 'In Transit',
        value: s.in_transit,
        icon: Truck,
        description: 'With courier',
        href: '/waybills',
      },
    ];
    statCards2 = [
      {
        title: 'Delivered Today',
        value: s.delivered_today,
        icon: CheckCircle2,
        variant: 'success',
      },
      {
        title: 'Pending Dispatch',
        value: s.pending_dispatch,
        icon: Clock,
        variant: 'warning',
        href: '/waybills',
      },
      { title: 'Total Waybills', value: s.total_waybills, icon: Truck, href: '/waybills' },
      { title: 'Total Leads', value: s.total_leads, icon: Users, href: '/leads' },
    ];
  } else if (isTeamLeader) {
    statCards1 = [
      {
        title: 'Sales Today',
        value: s.sales_today,
        icon: TrendingUp,
        variant: 'success',
        trend: { value: liveTrends?.sales ?? null, label: 'vs yesterday' },
      },
      {
        title: 'New Leads',
        value: s.new_leads,
        icon: Users,
        variant: 'success',
        description: 'Unassigned',
        href: '/leads',
      },
      { title: 'Conversion Rate', value: `${s.conversion_rate}%`, icon: BarChart3 },
      {
        title: 'Agents Online',
        value: s.agents_online,
        icon: Users,
        variant: 'success',
        description: 'Active in last hour',
        href: '/agents/governance',
      },
    ];
    statCards2 = [
      {
        title: 'QC Pending',
        value: s.qc_pending,
        icon: AlertCircle,
        variant: s.qc_pending > 10 ? 'danger' : 'warning',
        href: '/qc',
      },
      {
        title: 'Open Tickets',
        value: s.open_tickets ?? 0,
        icon: Headphones,
        variant: 'warning',
        href: '/tickets',
      },
      { title: 'Total Leads', value: s.total_leads, icon: Users, href: '/leads' },
      { title: 'Total Waybills', value: s.total_waybills, icon: Truck, href: '/waybills' },
    ];
  } else if (isChecker) {
    statCards1 = [
      {
        title: 'Pending Dispatch',
        value: s.pending_dispatch,
        icon: Clock,
        variant: 'warning',
        href: '/waybills',
      },
      { title: 'In Transit', value: s.in_transit, icon: Truck, href: '/waybills' },
      {
        title: 'Delivered Today',
        value: s.delivered_today,
        icon: CheckCircle2,
        variant: 'success',
        trend: { value: liveTrends?.delivered ?? null, label: 'vs yesterday' },
      },
      { title: 'Returns Today', value: s.returned_today, icon: XCircle, variant: 'danger' },
    ];
    statCards2 = [
      {
        title: 'QC Pending',
        value: s.qc_pending,
        icon: AlertCircle,
        variant: 'warning',
        href: '/qc',
      },
      { title: 'Total Waybills', value: s.total_waybills, icon: Truck, href: '/waybills' },
      { title: 'Total Leads', value: s.total_leads, icon: Users, href: '/leads' },
      { title: 'Total Products', value: s.total_products ?? 0, icon: Package, href: '/inventory' },
    ];
  } else if (isEncoder) {
    statCards1 = [
      {
        title: 'New Leads',
        value: s.new_leads,
        icon: Users,
        variant: 'success',
        description: 'Unassigned',
        href: '/leads',
      },
      {
        title: 'Pending Dispatch',
        value: s.pending_dispatch,
        icon: Clock,
        variant: 'warning',
        href: '/waybills',
      },
      { title: 'Total Waybills', value: s.total_waybills, icon: Truck, href: '/waybills' },
      {
        title: 'Open Tickets',
        value: s.open_tickets ?? 0,
        icon: Headphones,
        variant: 'warning',
        href: '/tickets',
      },
    ];
    statCards2 = [
      { title: 'Sales Today', value: s.sales_today, icon: TrendingUp, variant: 'success' },
      { title: 'Conversion Rate', value: `${s.conversion_rate}%`, icon: BarChart3 },
      { title: 'Total Leads', value: s.total_leads, icon: Users, href: '/leads' },
      {
        title: 'QC Pending',
        value: s.qc_pending,
        icon: AlertCircle,
        variant: 'warning',
        href: '/qc',
      },
    ];
  } else {
    // Admin / superadmin / supervisor — full dashboard
    statCards1 = [
      {
        title: 'Pending Dispatch',
        value: s.pending_dispatch,
        icon: Clock,
        variant: 'warning',
        description: 'Waybills awaiting scan',
        href: '/waybills',
      },
      {
        title: 'In Transit',
        value: s.in_transit,
        icon: Truck,
        description: 'With courier',
        href: '/waybills',
      },
      {
        title: 'Delivered Today',
        value: s.delivered_today,
        icon: CheckCircle2,
        variant: 'success',
        trend: { value: liveTrends?.delivered ?? null, label: 'vs yesterday' },
      },
      { title: 'Returns Today', value: s.returned_today, icon: XCircle, variant: 'danger' },
    ];
    statCards2 = [
      {
        title: 'New Leads',
        value: s.new_leads,
        icon: Users,
        variant: 'success',
        description: 'Unassigned',
        href: '/leads',
      },
      {
        title: 'Sales Today',
        value: s.sales_today,
        icon: TrendingUp,
        variant: 'success',
        trend: { value: liveTrends?.sales ?? null, label: 'vs yesterday' },
      },
      {
        title: 'QC Pending',
        value: s.qc_pending,
        icon: AlertCircle,
        variant: s.qc_pending > 10 ? 'danger' : 'warning',
        description: 'Awaiting review',
        href: '/qc',
      },
      {
        title: 'Agents Online',
        value: s.agents_online,
        icon: Users,
        variant: 'success',
        description: 'Active in last hour',
        href: '/agents/governance',
      },
    ];
  }

  // ── Role-based quick actions ──
  type QuickAction = {
    href?: string;
    action?: 'create_ticket' | 'import_waybills' | 'new_lead';
    label: string;
    desc: string;
    icon: React.ComponentType<{ className?: string }>;
    color: string;
  };
  let quickActions: QuickAction[] = [];

  // ── Create Ticket modal ──
  const [showTicketModal, setShowTicketModal] = useState(false);
  const ticketForm = useForm({
    subject: '',
    description: '',
    priority: 'medium',
    category: 'general',
    related_waybill: '',
  });

  function submitTicket(e: React.FormEvent) {
    e.preventDefault();
    ticketForm.post('/tickets', {
      onSuccess: () => {
        setShowTicketModal(false);
        ticketForm.reset();
        toast.success('Ticket created successfully.');
        refreshStats();
      },
      onError: () => toast.error('Failed to create ticket. Check the form fields.'),
    });
  }

  function handleQuickAction(action: string) {
    if (action === 'create_ticket') {
      setShowTicketModal(true);
    } else if (action === 'import_waybills') {
      router.visit('/waybills/import');
    } else if (action === 'new_lead') {
      router.visit('/lead-pool/import');
    }
  }

  if (isAgent) {
    quickActions = [
      {
        action: 'create_ticket',
        label: 'Create Ticket',
        desc: 'Open a support ticket',
        icon: Headphones,
        color: 'bg-warning/10 text-warning',
      },
      {
        href: '/leads',
        label: 'My Leads',
        desc: `${s.new_leads} new`,
        icon: Users,
        color: 'bg-success/10 text-success',
      },
      {
        href: '/qc',
        label: 'QC Review',
        desc: `${s.qc_pending} pending`,
        icon: ClipboardCheck,
        color: 'bg-primary/10 text-primary',
      },
      {
        href: '/tickets',
        label: 'My Tickets',
        desc: `${s.my_tickets ?? 0} open`,
        icon: Headphones,
        color: 'bg-warning/10 text-warning',
      },
      {
        href: '/scanner',
        label: 'Scanner',
        desc: 'Scan waybills',
        icon: QrCode,
        color: 'bg-primary/10 text-primary',
      },
    ];
  } else if (isFinance) {
    quickActions = [
      {
        action: 'create_ticket',
        label: 'Create Ticket',
        desc: 'Open a support ticket',
        icon: Headphones,
        color: 'bg-warning/10 text-warning',
      },
      {
        href: '/finance',
        label: 'Finance Overview',
        desc: 'Revenue & costs',
        icon: DollarSign,
        color: 'bg-success/10 text-success',
      },
      {
        href: '/finance/invoices',
        label: 'Invoices',
        desc: `${s.invoices_unpaid ?? 0} unpaid`,
        icon: FileText,
        color: 'bg-primary/10 text-primary',
      },
      {
        href: '/finance/cost-of-goods',
        label: 'Cost of Goods',
        desc: 'COGS analysis',
        icon: Package,
        color: 'bg-warning/10 text-warning',
      },
      {
        href: '/sales',
        label: 'Sales',
        desc: 'Sales dashboard',
        icon: TrendingUp,
        color: 'bg-success/10 text-success',
      },
    ];
  } else if (isWarehouse) {
    quickActions = [
      {
        action: 'import_waybills',
        label: 'Import Waybills',
        desc: 'Upload CSV file',
        icon: Truck,
        color: 'bg-info/10 text-info',
      },
      {
        href: '/scanner',
        label: 'Scanner',
        desc: 'Scan waybills',
        icon: QrCode,
        color: 'bg-primary/10 text-primary',
      },
      {
        href: '/inventory',
        label: 'Inventory',
        desc: `${s.low_stock_count ?? 0} low stock`,
        icon: Package,
        color: 'bg-warning/10 text-warning',
      },
      {
        href: '/waybills',
        label: 'Waybills',
        desc: `${s.pending_dispatch} pending`,
        icon: Truck,
        color: 'bg-info/10 text-info',
      },
      {
        href: '/inventory/adjustments',
        label: 'Adjustments',
        desc: 'Stock changes',
        icon: BarChart3,
        color: 'bg-primary/10 text-primary',
      },
    ];
  } else if (isClaims) {
    quickActions = [
      {
        action: 'create_ticket',
        label: 'Create Ticket',
        desc: 'Open a support ticket',
        icon: Headphones,
        color: 'bg-warning/10 text-warning',
      },
      {
        href: '/waybills/claims',
        label: 'Claims',
        desc: `${s.claims_pending ?? 0} pending`,
        icon: ShieldAlert,
        color: 'bg-warning/10 text-warning',
      },
      {
        href: '/waybills/claims/beyond-sla',
        label: 'Beyond SLA',
        desc: `${s.beyond_sla_count ?? 0} overdue`,
        icon: AlertCircle,
        color: 'bg-destructive/10 text-destructive',
      },
      {
        href: '/waybills',
        label: 'Waybills',
        desc: 'All shipments',
        icon: Truck,
        color: 'bg-info/10 text-info',
      },
      {
        href: '/scanner',
        label: 'Scanner',
        desc: 'Scan waybills',
        icon: QrCode,
        color: 'bg-primary/10 text-primary',
      },
    ];
  } else if (isTeamLeader) {
    quickActions = [
      {
        action: 'create_ticket',
        label: 'Create Ticket',
        desc: 'Open a support ticket',
        icon: Headphones,
        color: 'bg-warning/10 text-warning',
      },
      {
        href: '/leads',
        label: 'Leads',
        desc: `${s.new_leads} new`,
        icon: Users,
        color: 'bg-success/10 text-success',
      },
      {
        href: '/distribution',
        label: 'Distribution',
        desc: 'Assign leads',
        icon: UserCog,
        color: 'bg-primary/10 text-primary',
      },
      {
        href: '/qc',
        label: 'QC Review',
        desc: `${s.qc_pending} pending`,
        icon: ClipboardCheck,
        color: 'bg-warning/10 text-warning',
      },
      {
        href: '/agents/governance',
        label: 'Agents',
        desc: `${s.agents_online} online`,
        icon: Users,
        color: 'bg-success/10 text-success',
      },
    ];
  } else if (isChecker) {
    quickActions = [
      {
        href: '/scanner',
        label: 'Scanner',
        desc: 'Scan waybills',
        icon: QrCode,
        color: 'bg-primary/10 text-primary',
      },
      {
        href: '/waybills',
        label: 'Waybills',
        desc: `${s.pending_dispatch} pending`,
        icon: Truck,
        color: 'bg-info/10 text-info',
      },
      {
        href: '/qc',
        label: 'QC Review',
        desc: `${s.qc_pending} pending`,
        icon: ClipboardCheck,
        color: 'bg-warning/10 text-warning',
      },
      {
        href: '/inventory',
        label: 'Inventory',
        desc: 'Stock levels',
        icon: Package,
        color: 'bg-primary/10 text-primary',
      },
    ];
  } else if (isEncoder) {
    quickActions = [
      {
        action: 'new_lead',
        label: 'New Lead',
        desc: 'Import a lead',
        icon: Users,
        color: 'bg-success/10 text-success',
      },
      {
        action: 'import_waybills',
        label: 'Import Waybills',
        desc: 'Upload CSV file',
        icon: Truck,
        color: 'bg-info/10 text-info',
      },
      {
        action: 'create_ticket',
        label: 'Create Ticket',
        desc: 'Open a support ticket',
        icon: Headphones,
        color: 'bg-warning/10 text-warning',
      },
      {
        href: '/orders',
        label: 'Orders',
        desc: 'Manage orders',
        icon: ClipboardCheck,
        color: 'bg-primary/10 text-primary',
      },
      {
        href: '/tickets',
        label: 'Tickets',
        desc: `${s.open_tickets ?? 0} open`,
        icon: Headphones,
        color: 'bg-warning/10 text-warning',
      },
    ];
  } else {
    // Admin / superadmin / supervisor
    quickActions = [
      {
        action: 'create_ticket',
        label: 'Create Ticket',
        desc: 'Open a support ticket',
        icon: Headphones,
        color: 'bg-warning/10 text-warning',
      },
      {
        action: 'import_waybills',
        label: 'Import Waybills',
        desc: 'Upload CSV file',
        icon: Truck,
        color: 'bg-info/10 text-info',
      },
      {
        href: '/scanner',
        label: 'Scanner',
        desc: 'Scan waybills',
        icon: QrCode,
        color: 'bg-primary/10 text-primary',
      },
      {
        href: '/leads',
        label: 'Leads',
        desc: `${s.new_leads} new`,
        icon: Users,
        color: 'bg-success/10 text-success',
      },
      {
        href: '/qc',
        label: 'QC Review',
        desc: `${s.qc_pending} pending`,
        icon: ClipboardCheck,
        color: 'bg-primary/10 text-primary',
      },
      {
        href: '/recycling/pool',
        label: 'Recycling',
        desc: 'Lead pool',
        icon: Recycle,
        color: 'bg-warning/10 text-warning',
      },
    ];
  }

  // ── Role-based summary stats (bottom of chart) ──
  let summaryStats: { label: string; value: string; color?: string }[] = [];

  if (isFinance) {
    summaryStats = [
      {
        label: 'Total Revenue',
        value: `₱${(s.total_revenue ?? 0).toLocaleString()}`,
        color: 'text-success',
      },
      { label: 'Total Leads', value: s.total_leads.toLocaleString() },
      { label: 'Conversion Rate', value: `${s.conversion_rate}%` },
    ];
  } else if (isWarehouse) {
    summaryStats = [
      { label: 'Total Waybills', value: s.total_waybills.toLocaleString() },
      { label: 'Total Products', value: (s.total_products ?? 0).toLocaleString() },
      {
        label: 'Low Stock',
        value: (s.low_stock_count ?? 0).toLocaleString(),
        color: 'text-warning',
      },
    ];
  } else if (isClaims) {
    summaryStats = [
      { label: 'Total Waybills', value: s.total_waybills.toLocaleString() },
      {
        label: 'Claims Pending',
        value: (s.claims_pending ?? 0).toLocaleString(),
        color: 'text-warning',
      },
      {
        label: 'Beyond SLA',
        value: (s.beyond_sla_count ?? 0).toLocaleString(),
        color: 'text-destructive',
      },
    ];
  } else {
    summaryStats = [
      { label: 'Total Waybills', value: s.total_waybills.toLocaleString() },
      { label: 'Total Leads', value: s.total_leads.toLocaleString() },
      { label: 'Conversion Rate', value: `${s.conversion_rate}%`, color: 'text-success' },
    ];
  }

  const chartData =
    liveHourly.length > 0
      ? liveHourly
      : Array.from({ length: 12 }, (_, i) => ({ hour: String(8 + i), waybills: 0 }));

  const chartMax = Math.max(...chartData.map((d) => d.waybills), 1);

  const today = new Date();
  const dateLabel = today.toLocaleDateString(undefined, {
    weekday: 'long',
    month: 'short',
    day: 'numeric',
  });

  // ── Role-based header title ──
  const roleTitles: Record<string, string> = {
    agent: 'Agent Dashboard',
    finance: 'Finance Dashboard',
    accounting: 'Finance Dashboard',
    warehouse: 'Warehouse Dashboard',
    teamleader: 'Team Leader Dashboard',
    checker: 'Operations Dashboard',
    encoder: 'Data Entry Dashboard',
    claims_officer: 'Claims Dashboard',
    superadmin: 'Dashboard',
    admin: 'Dashboard',
    supervisor: 'Dashboard',
  };

  const roleDescriptions: Record<string, string> = {
    agent: 'Your leads, sales, and assigned tickets',
    finance: 'Revenue, invoices, and financial overview',
    accounting: 'Revenue, invoices, and financial overview',
    warehouse: 'Shipments, inventory, and stock levels',
    teamleader: 'Team performance and lead distribution',
    checker: 'Waybill scanning and QC operations',
    encoder: 'Data entry and order management',
    claims_officer: 'Returns, claims, and SLA tracking',
    superadmin: 'Overview of warehouse operations and key metrics',
    admin: 'Overview of warehouse operations and key metrics',
    supervisor: 'Overview of warehouse operations and key metrics',
  };

  return (
    <AppLayout>
      <Head title="Dashboard" />

      <div className="space-y-4">
        {/* Header */}
        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div>
            <h1 className="text-xl font-bold tracking-tight font-display">
              {roleTitles[effectiveRole] ?? 'Dashboard'}
            </h1>
            <p className="text-muted-foreground">
              {roleDescriptions[effectiveRole] ??
                'Overview of warehouse operations and key metrics'}
            </p>
          </div>
          <div className="flex items-center gap-2">
            {lastUpdated && (
              <span className="text-xs text-muted-foreground hidden sm:inline">
                Updated {new Date(lastUpdated).toLocaleTimeString()}
              </span>
            )}
            <Button
              variant="outline"
              size="icon"
              onClick={refreshStats}
              disabled={refreshing}
              title="Refresh stats"
            >
              <RefreshCw className={`h-4 w-4 ${refreshing ? 'animate-spin' : ''}`} />
            </Button>
            <Button
              variant="outline"
              size="sm"
              onClick={() => setShowCustomize(true)}
              title="Customize dashboard layout"
            >
              <LayoutGrid className="mr-1.5 h-4 w-4" />
              Customize
            </Button>
            <Button asChild>
              <Link href="/scanner">
                <QrCode className="mr-1.5 h-4 w-4" />
                Open Scanner
              </Link>
            </Button>
          </div>
        </div>

        {/* Stat cards row 1 */}
        {widgetVisible('stat_cards_1') && (
          <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
            {statCards1.map((card) => (
              <StatCard key={card.title} {...card} />
            ))}
          </div>
        )}

        {/* Stat cards row 2 */}
        {widgetVisible('stat_cards_2') && (
          <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
            {statCards2.map((card) => (
              <StatCard key={card.title} {...card} />
            ))}
          </div>
        )}

        {/* Chart + Activity */}
        <div className="grid gap-6 lg:grid-cols-3">
          {widgetVisible('hourly_chart') && (
            <Card className="lg:col-span-2">
              <CardHeader>
                <div className="flex items-center justify-between">
                  <div>
                    <CardTitle>Today's Activity</CardTitle>
                    <CardDescription>Hourly waybill volume</CardDescription>
                  </div>
                  <Badge variant="outline" className="text-xs">
                    {dateLabel}
                  </Badge>
                </div>
              </CardHeader>
              <CardContent>
                {chartMax === 1 && chartData.every((d) => d.waybills === 0) ? (
                  <div className="h-32 flex items-center justify-center text-sm text-muted-foreground">
                    No waybill activity yet today
                  </div>
                ) : (
                  <div className="flex items-end gap-1.5 h-32">
                    {chartData.map((item) => {
                      const hour = parseInt(item.hour, 10);
                      const isCurrentHour = today.getHours() === hour;
                      const heightPct = Math.max(
                        (item.waybills / chartMax) * 100,
                        item.waybills > 0 ? 4 : 1
                      );
                      return (
                        <div
                          key={item.hour}
                          className="flex-1 flex flex-col items-center gap-1 group"
                        >
                          <div className="relative w-full">
                            {item.waybills > 0 && (
                              <span className="absolute -top-5 left-1/2 -translate-x-1/2 text-[9px] text-muted-foreground opacity-0 group-hover:opacity-100 transition-opacity whitespace-nowrap">
                                {item.waybills}
                              </span>
                            )}
                            <div
                              className={`w-full rounded-t transition-all ${
                                isCurrentHour ? 'bg-primary' : 'bg-primary/30 hover:bg-primary/60'
                              }`}
                              style={{ height: `${heightPct}%`, minHeight: '4px' }}
                            />
                          </div>
                          <span className="text-[10px] text-muted-foreground">
                            {hour > 12 ? hour - 12 : hour}
                            {hour >= 12 ? 'p' : 'a'}
                          </span>
                        </div>
                      );
                    })}
                  </div>
                )}

                <div className="mt-4 grid grid-cols-3 gap-4 pt-4 border-t">
                  {summaryStats.map((stat) => (
                    <div key={stat.label} className="text-center">
                      <p
                        className={`text-xl font-bold font-display tabular-nums ${stat.color ?? ''}`}
                      >
                        {stat.value}
                      </p>
                      <p className="text-xs text-muted-foreground">{stat.label}</p>
                    </div>
                  ))}
                </div>
              </CardContent>
            </Card>
          )}

          {widgetVisible('recent_activity') && (
            <Card>
              <CardHeader>
                <CardTitle>Recent Activity</CardTitle>
                <CardDescription>Latest system events</CardDescription>
              </CardHeader>
              <CardContent>
                {liveActivity.length === 0 ? (
                  <p className="text-sm text-muted-foreground text-center py-8">
                    No recent activity
                  </p>
                ) : (
                  <div className="space-y-4">
                    {liveActivity.slice(0, 6).map((activity) => {
                      const Icon = ACTIVITY_ICONS[activity.type] ?? BarChart3;
                      const color = ACTIVITY_COLORS[activity.type] ?? 'bg-muted-foreground';
                      return (
                        <div key={activity.id} className="flex items-start gap-3">
                          <div className={`rounded-full p-1.5 shrink-0 ${color}`}>
                            <Icon className="h-3 w-3 text-white" />
                          </div>
                          <div className="flex-1 min-w-0">
                            <p className="text-sm truncate">{activity.message}</p>
                            <p className="text-xs text-muted-foreground">{activity.time}</p>
                          </div>
                        </div>
                      );
                    })}
                  </div>
                )}
              </CardContent>
            </Card>
          )}
        </div>

        {/* Quick Actions */}
        {widgetVisible('quick_actions') && (
          <Card>
            <CardHeader>
              <CardTitle>Quick Actions</CardTitle>
              <CardDescription>Frequently used operations</CardDescription>
            </CardHeader>
            <CardContent>
              <div className="grid gap-3 md:grid-cols-2 lg:grid-cols-4">
                {quickActions.map((item) => {
                  const key = item.href ?? item.action ?? item.label;
                  if (item.action) {
                    const action = item.action;
                    return (
                      <button
                        key={key}
                        onClick={() => handleQuickAction(action)}
                        className="flex items-center justify-between rounded-lg border p-4 hover:bg-accent transition-colors group text-left"
                      >
                        <div className="flex items-center gap-3">
                          <div className={`rounded-lg p-2 ${item.color}`}>
                            <item.icon className="h-5 w-5" />
                          </div>
                          <div>
                            <p className="font-medium">{item.label}</p>
                            <p className="text-xs text-muted-foreground">{item.desc}</p>
                          </div>
                        </div>
                        <ArrowRight className="h-4 w-4 text-muted-foreground group-hover:text-primary transition-colors" />
                      </button>
                    );
                  }
                  return (
                    <Link
                      key={key}
                      href={item.href!}
                      className="flex items-center justify-between rounded-lg border p-4 hover:bg-accent transition-colors group"
                    >
                      <div className="flex items-center gap-3">
                        <div className={`rounded-lg p-2 ${item.color}`}>
                          <item.icon className="h-5 w-5" />
                        </div>
                        <div>
                          <p className="font-medium">{item.label}</p>
                          <p className="text-xs text-muted-foreground">{item.desc}</p>
                        </div>
                      </div>
                      <ArrowRight className="h-4 w-4 text-muted-foreground group-hover:text-primary transition-colors" />
                    </Link>
                  );
                })}
              </div>
            </CardContent>
          </Card>
        )}

        {/* Alerts Widget */}
        {widgetVisible('alerts_widget') && (
          <Card>
            <CardHeader>
              <div className="flex items-center justify-between">
                <div>
                  <CardTitle className="flex items-center gap-2">
                    <Bell className="h-5 w-5" />
                    Alerts
                  </CardTitle>
                  <CardDescription>Operational alerts requiring attention</CardDescription>
                </div>
                {liveAlerts.length > 0 && (
                  <Badge variant="destructive" className="text-xs">
                    {liveAlerts.length} active
                  </Badge>
                )}
              </div>
            </CardHeader>
            <CardContent>
              {liveAlerts.length === 0 ? (
                <div className="flex flex-col items-center justify-center py-8 text-center">
                  <CheckCircle2 className="h-8 w-8 text-success mb-2" />
                  <p className="text-sm text-muted-foreground">
                    No active alerts — all systems normal
                  </p>
                </div>
              ) : (
                <div className="space-y-2 max-h-[320px] overflow-y-auto">
                  {liveAlerts.map((alert, idx) => {
                    const isCritical = alert.severity === 'critical';
                    const iconMap: Record<string, React.ComponentType<{ className?: string }>> = {
                      low_stock: PackageX,
                      sla_breach: AlertTriangle,
                      failed_import: FileWarning,
                      undelivered: TruckIcon,
                    };
                    const Icon = iconMap[alert.type] ?? AlertCircle;
                    return (
                      <Link
                        key={`${alert.type}-${idx}`}
                        href={alert.href}
                        className={`flex items-start gap-3 rounded-lg border p-3 hover:bg-accent transition-colors group ${
                          isCritical
                            ? 'border-destructive/30 bg-destructive/5'
                            : 'border-warning/30 bg-warning/5'
                        }`}
                      >
                        <div
                          className={`rounded-lg p-2 shrink-0 ${
                            isCritical
                              ? 'bg-destructive/10 text-destructive'
                              : 'bg-warning/10 text-warning'
                          }`}
                        >
                          <Icon className="h-4 w-4" />
                        </div>
                        <div className="flex-1 min-w-0">
                          <div className="flex items-center gap-2">
                            <p className="font-medium text-sm truncate">{alert.title}</p>
                            <Badge
                              variant={isCritical ? 'destructive' : 'outline'}
                              className="text-[10px] px-1.5 py-0"
                            >
                              {isCritical ? 'Critical' : 'Warning'}
                            </Badge>
                          </div>
                          <p className="text-xs text-muted-foreground truncate">
                            {alert.description}
                          </p>
                        </div>
                        <ArrowRight className="h-4 w-4 text-muted-foreground group-hover:text-primary transition-colors shrink-0" />
                      </Link>
                    );
                  })}
                </div>
              )}
            </CardContent>
          </Card>
        )}

        {/* Revenue Summary Widget */}
        {widgetVisible('revenue_summary') && liveRevenue && (
          <Card>
            <CardHeader>
              <div className="flex items-center justify-between">
                <div>
                  <CardTitle className="flex items-center gap-2">
                    <Wallet className="h-5 w-5" />
                    Revenue Summary
                  </CardTitle>
                  <CardDescription>Today / week / month with top products</CardDescription>
                </div>
              </div>
            </CardHeader>
            <CardContent className="space-y-6">
              {/* Period revenue cards */}
              <div className="grid grid-cols-3 gap-4">
                {(
                  [
                    { key: 'today', label: 'Today' },
                    { key: 'week', label: 'This Week' },
                    { key: 'month', label: 'This Month' },
                  ] as const
                ).map(({ key, label }) => {
                  const period = liveRevenue.periods[key];
                  const trend = period.trend;
                  const TrendIcon = trend !== null && trend >= 0 ? TrendingUp : TrendingDown;
                  return (
                    <div key={key} className="rounded-lg border p-4">
                      <p className="text-xs text-muted-foreground mb-1">{label}</p>
                      <p className="text-2xl font-bold font-display tabular-nums">
                        ₱
                        {period.value.toLocaleString('en-PH', {
                          minimumFractionDigits: 2,
                          maximumFractionDigits: 2,
                        })}
                      </p>
                      {trend !== null && (
                        <div
                          className={`flex items-center gap-1 mt-1 text-xs ${
                            trend >= 0 ? 'text-success' : 'text-destructive'
                          }`}
                        >
                          <TrendIcon className="h-3 w-3" />
                          <span>
                            {trend >= 0 ? '+' : ''}
                            {trend}% vs prev
                          </span>
                        </div>
                      )}
                    </div>
                  );
                })}
              </div>

              {/* Conversion trend mini bar chart */}
              <div>
                <p className="text-sm font-medium mb-2">Conversion Trend (7 days)</p>
                <div className="flex items-end gap-2 h-24">
                  {liveRevenue.conversion_trend.map((day) => {
                    const maxConversion = Math.max(
                      ...liveRevenue.conversion_trend.map((d) => d.conversion),
                      1
                    );
                    const heightPct = Math.max((day.conversion / maxConversion) * 100, 4);
                    return (
                      <div key={day.date} className="flex-1 flex flex-col items-center gap-1 group">
                        <span className="text-[9px] text-muted-foreground opacity-0 group-hover:opacity-100 transition-opacity">
                          {day.conversion}%
                        </span>
                        <div
                          className="w-full rounded-t bg-primary/30 hover:bg-primary/60 transition-colors"
                          style={{ height: `${heightPct}%`, minHeight: '4px' }}
                        />
                        <span className="text-[10px] text-muted-foreground">{day.label}</span>
                      </div>
                    );
                  })}
                </div>
              </div>

              {/* Top products */}
              <div>
                <p className="text-sm font-medium mb-2">Top Products (30 days)</p>
                {liveRevenue.top_products.length === 0 ? (
                  <p className="text-sm text-muted-foreground text-center py-4">
                    No delivered orders in the last 30 days
                  </p>
                ) : (
                  <div className="space-y-2">
                    {liveRevenue.top_products.map((product, idx) => (
                      <div
                        key={product.id}
                        className="flex items-center gap-3 rounded-lg border p-2.5"
                      >
                        <div className="flex items-center justify-center w-6 h-6 rounded-full bg-primary/10 text-xs font-bold text-primary shrink-0">
                          {idx + 1}
                        </div>
                        <div className="flex-1 min-w-0">
                          <p className="text-sm font-medium truncate">{product.name}</p>
                          <p className="text-xs text-muted-foreground">
                            {product.order_count} orders
                            {product.sku && ` · ${product.sku}`}
                          </p>
                        </div>
                        <p className="text-sm font-semibold tabular-nums shrink-0">
                          ₱
                          {product.revenue.toLocaleString('en-PH', {
                            minimumFractionDigits: 2,
                            maximumFractionDigits: 2,
                          })}
                        </p>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            </CardContent>
          </Card>
        )}

        {/* Create Ticket Dialog */}
        <Dialog open={showTicketModal} onOpenChange={setShowTicketModal}>
          <DialogContent className="sm:max-w-[480px]">
            <DialogHeader>
              <DialogTitle>Create Support Ticket</DialogTitle>
              <DialogDescription>
                Open a new support ticket. It will be assigned and routed automatically.
              </DialogDescription>
            </DialogHeader>
            <form onSubmit={submitTicket} className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="ticket-subject">Subject</Label>
                <Input
                  id="ticket-subject"
                  value={ticketForm.data.subject}
                  onChange={(e) => ticketForm.setData('subject', e.target.value)}
                  placeholder="Brief description of the issue"
                  required
                />
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label htmlFor="ticket-priority">Priority</Label>
                  <Select
                    value={ticketForm.data.priority}
                    onValueChange={(v) => ticketForm.setData('priority', v)}
                  >
                    <SelectTrigger id="ticket-priority">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="low">Low</SelectItem>
                      <SelectItem value="medium">Medium</SelectItem>
                      <SelectItem value="high">High</SelectItem>
                      <SelectItem value="urgent">Urgent</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="ticket-category">Category</Label>
                  <Select
                    value={ticketForm.data.category}
                    onValueChange={(v) => ticketForm.setData('category', v)}
                  >
                    <SelectTrigger id="ticket-category">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="general">General</SelectItem>
                      <SelectItem value="technical">Technical</SelectItem>
                      <SelectItem value="billing">Billing</SelectItem>
                      <SelectItem value="logistics">Logistics</SelectItem>
                      <SelectItem value="quality">Quality</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              </div>
              <div className="space-y-2">
                <Label htmlFor="ticket-desc">Description</Label>
                <Textarea
                  id="ticket-desc"
                  value={ticketForm.data.description}
                  onChange={(e) => ticketForm.setData('description', e.target.value)}
                  placeholder="Provide details about the issue..."
                  rows={4}
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="ticket-waybill">Related Waybill (optional)</Label>
                <Input
                  id="ticket-waybill"
                  value={ticketForm.data.related_waybill}
                  onChange={(e) => ticketForm.setData('related_waybill', e.target.value)}
                  placeholder="Waybill number if applicable"
                />
              </div>
              <DialogFooter>
                <Button type="button" variant="outline" onClick={() => setShowTicketModal(false)}>
                  Cancel
                </Button>
                <Button type="submit" disabled={ticketForm.processing}>
                  {ticketForm.processing ? 'Creating...' : 'Create Ticket'}
                </Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>

        {/* Customize Layout Dialog */}
        <Dialog open={showCustomize} onOpenChange={setShowCustomize}>
          <DialogContent className="sm:max-w-[520px]">
            <DialogHeader>
              <DialogTitle>Customize Dashboard</DialogTitle>
              <DialogDescription>
                Drag to reorder widgets. Toggle visibility on or off. Click Save to apply your
                layout.
              </DialogDescription>
            </DialogHeader>
            <div className="space-y-2 max-h-[400px] overflow-y-auto">
              {widgets.map((w, idx) => (
                <div
                  key={w.key}
                  draggable
                  onDragStart={() => handleDragStart(idx)}
                  onDragOver={(e) => handleDragOver(e, idx)}
                  onDrop={() => handleDrop(idx)}
                  onDragEnd={() => {
                    setDraggedIdx(null);
                    setDragOverIdx(null);
                  }}
                  className={`flex items-center gap-3 rounded-lg border p-3 transition-all ${
                    draggedIdx === idx
                      ? 'opacity-50 border-primary'
                      : dragOverIdx === idx
                        ? 'border-primary bg-primary/5'
                        : 'border-border'
                  } ${!w.is_visible ? 'opacity-60' : ''}`}
                >
                  <GripVertical className="h-5 w-5 text-muted-foreground cursor-grab shrink-0" />
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2">
                      <p className="font-medium text-sm">{w.label}</p>
                      {w.is_visible ? (
                        <Eye className="h-3.5 w-3.5 text-muted-foreground" />
                      ) : (
                        <EyeOff className="h-3.5 w-3.5 text-muted-foreground" />
                      )}
                    </div>
                    <p className="text-xs text-muted-foreground truncate">{w.description}</p>
                  </div>
                  <Switch
                    checked={w.is_visible}
                    onCheckedChange={() => toggleWidgetVisibility(w.key)}
                  />
                </div>
              ))}
            </div>
            <DialogFooter className="flex-col sm:flex-row gap-2">
              <Button
                variant="outline"
                onClick={handleResetLayout}
                disabled={savingWidgets}
                className="sm:mr-auto"
              >
                <RotateCcw className="mr-1.5 h-4 w-4" />
                Reset to Defaults
              </Button>
              <Button variant="outline" onClick={() => setShowCustomize(false)}>
                Cancel
              </Button>
              <Button onClick={handleSaveLayout} disabled={savingWidgets}>
                {savingWidgets ? 'Saving...' : 'Save Layout'}
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      </div>
    </AppLayout>
  );
}
