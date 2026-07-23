import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
  Plus,
  Search,
  Pencil,
  Trash2,
  Power,
  MessageSquare,
  Copy,
  X,
  Star,
  BarChart3,
  History,
  RotateCcw,
  Check,
  XCircle,
  FlaskConical,
  Play,
  Pause,
  Trophy,
} from 'lucide-react';
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from 'recharts';

interface FacebookPage {
  id: number;
  page_name: string;
}

interface ReplyTemplate {
  id: number;
  title: string;
  content: string;
  variables?: string[] | null;
  category?: string | null;
  intent?: string | null;
  allowed_roles?: string[] | null;
  shortcut: string | null;
  facebook_page_id: number | null;
  is_active: boolean;
  is_favorited?: boolean;
  usage_count: number;
  created_at: string;
  updated_at: string;
  facebook_page?: { id: number; page_name: string } | null;
  creator?: { id: number; name: string } | null;
  shared_pages?: { id: number; page_name: string }[];
  approval_status?: string | null;
  approved_by?: number | null;
  approved_at?: string | null;
  rejection_reason?: string | null;
  approver?: { id: number; name: string } | null;
}

const INTENT_OPTIONS = [
  { value: 'greeting', label: 'Greeting' },
  { value: 'order_confirmation', label: 'Order Confirmation' },
  { value: 'shipping_update', label: 'Shipping Update' },
  { value: 'payment_reminder', label: 'Payment Reminder' },
  { value: 'follow_up', label: 'Follow Up' },
  { value: 'apology', label: 'Apology' },
  { value: 'closing', label: 'Closing' },
  { value: 'faq', label: 'FAQ' },
  { value: 'escalation', label: 'Escalation' },
  { value: 'other', label: 'Other' },
];

const AVAILABLE_VARIABLES = [
  { key: '{customer_name}', desc: 'Customer display name' },
  { key: '{phone}', desc: 'Customer phone number' },
  { key: '{address}', desc: 'Customer delivery address' },
  { key: '{order_number}', desc: 'Most recent order number' },
  { key: '{tracking_number}', desc: 'Most recent tracking number' },
  { key: '{courier}', desc: 'Most recent order courier' },
  { key: '{total_amount}', desc: 'Most recent order total (₱)' },
  { key: '{page_name}', desc: 'Facebook page name' },
  { key: '{status}', desc: 'Conversation status' },
  { key: '{last_message}', desc: 'Last message preview' },
  { key: '{agent_name}', desc: 'Assigned agent name' },
];

interface PaginatedTemplates {
  data: ReplyTemplate[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from: number | null;
  to: number | null;
  links: { url: string | null; label: string; active: boolean }[];
}

interface UsageAnalytics {
  total_uses: number;
  uses_this_month: number;
  uses_last_30_days: number;
  top_templates: { id: number; title: string | null; count: number }[];
  top_users: { id: number; name: string | null; count: number }[];
  daily_usage: { date: string; count: number }[];
}

interface PerformanceMetrics {
  approval_stats: {
    pending: number;
    approved: number;
    rejected: number;
    no_status: number;
  };
  avg_approval_time_seconds: number | null;
  rejection_rate: number;
  template_performance: {
    id: number;
    title: string;
    category: string | null;
    intent: string | null;
    total_uses: number;
    unique_users: number;
    unique_conversations: number;
    resolved_conversations: number;
    resolution_rate: number;
    last_used: string | null;
  }[];
  category_performance: {
    category: string;
    template_count: number;
    total_usage: number;
  }[];
  intent_performance: {
    intent: string;
    template_count: number;
    total_usage: number;
  }[];
  usage_trend: {
    last_7_days: number;
    prev_7_days: number;
    direction: string;
    percent_change: number;
  };
}

interface AbTestVariant {
  id: number;
  label: string;
  weight: number;
  template_id: number;
  template_title: string | null;
  impressions: number;
  uses: number;
  conversations_resolved: number;
  conversion_rate: number;
  resolution_rate: number;
}

interface AbTest {
  id: number;
  name: string;
  description: string | null;
  status: string;
  created_by: string | null;
  start_at: string | null;
  end_at: string | null;
  winning_variant: { id: number; label: string } | null;
  variants: AbTestVariant[];
}

interface Props {
  templates: PaginatedTemplates;
  pages: FacebookPage[];
  categories: string[];
  intents: string[];
  roles: string[];
  analytics: UsageAnalytics;
  performance: PerformanceMetrics;
  approval_statuses: string[];
  ab_tests: AbTest[];
  filters: {
    search: string;
    page_id: string;
    category: string;
    intent: string;
    approval_status: string;
    favorites_only: boolean;
    active_only: boolean;
  };
}

export default function ReplyTemplatesIndex({
  templates,
  pages,
  categories,
  intents,
  roles,
  analytics,
  performance,
  approval_statuses,
  ab_tests,
  filters,
}: Props) {
  const [search, setSearch] = useState(filters.search);
  const [pageFilter, setPageFilter] = useState(filters.page_id);
  const [categoryFilter, setCategoryFilter] = useState(filters.category);
  const [intentFilter, setIntentFilter] = useState(filters.intent);
  const [approvalFilter, setApprovalFilter] = useState(filters.approval_status);
  const [favoritesOnly, setFavoritesOnly] = useState(filters.favorites_only);
  const [activeOnly, setActiveOnly] = useState(filters.active_only);
  const [showModal, setShowModal] = useState(false);
  const [editing, setEditing] = useState<ReplyTemplate | null>(null);
  const [form, setForm] = useState({
    title: '',
    content: '',
    category: '',
    intent: '',
    allowed_roles: [] as string[],
    shortcut: '',
    facebook_page_id: '',
    shared_page_ids: [] as number[],
    is_active: true,
  });
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [deleteId, setDeleteId] = useState<number | null>(null);
  const [versionTemplateId, setVersionTemplateId] = useState<number | null>(null);
  const [versions, setVersions] = useState<
    {
      id: number;
      version_number: number;
      title: string;
      change_summary: string | null;
      edited_by: string | null;
      created_at: string;
    }[]
  >([]);
  const [versionDetail, setVersionDetail] = useState<{
    id: number;
    version_number: number;
    title: string;
    content: string;
    change_summary: string | null;
    edited_by: string | null;
    created_at: string;
  } | null>(null);
  const [restoring, setRestoring] = useState(false);

  const [showAbTestModal, setShowAbTestModal] = useState(false);
  const [abTestForm, setAbTestForm] = useState({
    name: '',
    description: '',
    template_ids: [] as number[],
    weights: [] as number[],
  });

  const [abTestSaving, setAbTestSaving] = useState(false);
  const [abTestResults, setAbTestResults] = useState<{
    test: {
      id: number;
      name: string;
      description: string | null;
      status: string;
      created_by: string | null;
      start_at: string | null;
      end_at: string | null;
    };
    variants: AbTestVariant[];
    summary: {
      total_impressions: number;
      total_uses: number;
      total_resolved: number;
      overall_conversion_rate: number;
      overall_resolution_rate: number;
      best_variant: AbTestVariant | null;
    };
  } | null>(null);

  const [abTestLoading, setAbTestLoading] = useState(false);

  const applyFilters = () => {
    router.get(
      '/shop/reply-templates',
      {
        search: search || undefined,
        page_id: pageFilter || undefined,
        category: categoryFilter || undefined,
        intent: intentFilter || undefined,
        approval_status: approvalFilter || undefined,
        favorites_only: favoritesOnly,
        active_only: activeOnly,
      },
      { preserveScroll: true, preserveState: true }
    );
  };

  const openCreate = () => {
    setEditing(null);
    setForm({
      title: '',
      content: '',
      category: '',
      intent: '',
      allowed_roles: [] as string[],
      shortcut: '',
      facebook_page_id: '',
      shared_page_ids: [] as number[],
      is_active: true,
    });
    setError(null);
    setShowModal(true);
  };

  const openEdit = (template: ReplyTemplate) => {
    setEditing(template);
    setForm({
      title: template.title,
      content: template.content,
      category: template.category ?? '',
      intent: template.intent ?? '',
      allowed_roles: template.allowed_roles ?? [],
      shortcut: template.shortcut ?? '',
      facebook_page_id: template.facebook_page_id?.toString() ?? '',
      shared_page_ids: (template.shared_pages ?? []).map((p) => p.id),
      is_active: template.is_active,
    });
    setError(null);
    setShowModal(true);
  };

  const save = () => {
    setSaving(true);
    setError(null);

    const payload = {
      title: form.title,
      content: form.content,
      category: form.category || null,
      intent: form.intent || null,
      allowed_roles: form.allowed_roles.length > 0 ? form.allowed_roles : null,
      shortcut: form.shortcut || null,
      facebook_page_id: form.facebook_page_id || null,
      shared_page_ids: form.shared_page_ids.length > 0 ? form.shared_page_ids : [],
      is_active: form.is_active,
    };

    if (editing) {
      router.put(`/api/reply-templates/${editing.id}`, payload, {
        preserveScroll: true,
        onSuccess: () => {
          setSaving(false);
          setShowModal(false);
          router.reload({ only: ['templates'] });
        },
        onError: (errors) => {
          setSaving(false);
          setError(Object.values(errors).join(' '));
        },
      });
    } else {
      router.post('/api/reply-templates', payload, {
        preserveScroll: true,
        onSuccess: () => {
          setSaving(false);
          setShowModal(false);
          router.reload({ only: ['templates'] });
        },
        onError: (errors) => {
          setSaving(false);
          setError(Object.values(errors).join(' '));
        },
      });
    }
  };

  const toggleActive = (template: ReplyTemplate) => {
    router.post(
      `/api/reply-templates/${template.id}/toggle`,
      {},
      {
        preserveScroll: true,
        onSuccess: () => router.reload({ only: ['templates'] }),
      }
    );
  };

  const confirmDelete = (id: number) => {
    setDeleteId(id);
  };

  const doDelete = () => {
    if (!deleteId) return;
    router.delete(`/api/reply-templates/${deleteId}`, {
      preserveScroll: true,
      onSuccess: () => {
        setDeleteId(null);
        router.reload({ only: ['templates'] });
      },
    });
  };

  const toggleFavorite = (template: ReplyTemplate) => {
    router.post(
      `/api/reply-templates/${template.id}/favorite`,
      {},
      {
        preserveScroll: true,
        onSuccess: () => router.reload({ only: ['templates'] }),
      }
    );
  };

  const copyContent = (content: string) => {
    navigator.clipboard.writeText(content);
  };

  const openVersionHistory = (templateId: number) => {
    setVersionTemplateId(templateId);
    setVersionDetail(null);
    fetch(`/api/reply-templates/${templateId}/versions`)
      .then((res) => res.json())
      .then((data) => setVersions(data.versions ?? []));
  };

  const viewVersion = (versionId: number) => {
    if (!versionTemplateId) return;
    fetch(`/api/reply-templates/${versionTemplateId}/versions/${versionId}`)
      .then((res) => res.json())
      .then((data) => setVersionDetail(data.version));
  };

  const restoreVersion = (versionId: number) => {
    if (!versionTemplateId) return;
    setRestoring(true);
    router.post(
      `/api/reply-templates/${versionTemplateId}/versions/${versionId}/restore`,
      {},
      {
        preserveScroll: true,
        onSuccess: () => {
          setRestoring(false);
          setVersionTemplateId(null);
          setVersionDetail(null);
          router.reload({ only: ['templates'] });
        },
        onError: () => {
          setRestoring(false);
        },
      }
    );
  };

  const approveTemplate = (template: ReplyTemplate) => {
    router.post(
      `/api/reply-templates/${template.id}/approve`,
      {},
      {
        preserveScroll: true,
        onSuccess: () => router.reload({ only: ['templates'] }),
      }
    );
  };

  const rejectTemplate = (template: ReplyTemplate) => {
    const reason = prompt('Rejection reason (optional):');
    if (reason === null) return;
    router.post(
      `/api/reply-templates/${template.id}/reject`,
      { rejection_reason: reason || undefined },
      {
        preserveScroll: true,
        onSuccess: () => router.reload({ only: ['templates'] }),
      }
    );
  };

  // ─── A/B Test Handlers ───

  const toggleAbTestTemplate = (templateId: number) => {
    setAbTestForm((prev) => {
      const ids = prev.template_ids.includes(templateId)
        ? prev.template_ids.filter((id) => id !== templateId)
        : [...prev.template_ids, templateId];
      const weights = ids.map(() => Math.floor(100 / ids.length));
      return { ...prev, template_ids: ids, weights };
    });
  };

  const createAbTest = () => {
    if (abTestForm.template_ids.length < 2 || !abTestForm.name.trim()) return;
    setAbTestSaving(true);
    router.post(
      '/api/reply-templates/ab-tests',
      {
        name: abTestForm.name,
        description: abTestForm.description || undefined,
        template_ids: abTestForm.template_ids,
        weights: abTestForm.weights,
      },
      {
        preserveScroll: true,
        onSuccess: () => {
          setAbTestSaving(false);
          setShowAbTestModal(false);
          setAbTestForm({ name: '', description: '', template_ids: [], weights: [] });
          router.reload({ only: ['ab_tests'] });
        },
        onError: () => setAbTestSaving(false),
      }
    );
  };

  const updateAbTestStatus = (testId: number, status: string) => {
    router.patch(
      `/api/reply-templates/ab-tests/${testId}/status`,
      { status },
      {
        preserveScroll: true,
        onSuccess: () => router.reload({ only: ['ab_tests'] }),
      }
    );
  };

  const endAbTest = (testId: number) => {
    router.post(
      `/api/reply-templates/ab-tests/${testId}/end`,
      {},
      {
        preserveScroll: true,
        onSuccess: () => router.reload({ only: ['ab_tests'] }),
      }
    );
  };

  const viewAbTestResults = (testId: number) => {
    setAbTestLoading(true);
    fetch(`/api/reply-templates/ab-tests/${testId}/results`)
      .then((res) => res.json())
      .then((data) => setAbTestResults(data))
      .catch(() => setAbTestResults(null))
      .finally(() => setAbTestLoading(false));
  };

  return (
    <AppLayout>
      <div className="space-y-6 p-6">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div>
            <h1 className="flex items-center gap-2 text-2xl font-bold">
              <MessageSquare className="h-7 w-7 text-info" />
              Reply Templates
            </h1>
            <p className="mt-1 text-sm text-muted-foreground">
              Manage reusable reply templates with shortcuts for faster conversation responses.
            </p>
          </div>
          <div className="flex gap-2">
            <Link href="/shop/inbox">
              <Button variant="outline" size="sm">
                Back to Inbox
              </Button>
            </Link>
            <Button onClick={openCreate} size="sm">
              <Plus className="mr-1.5 h-4 w-4" />
              New Template
            </Button>
            <Button variant="outline" size="sm" onClick={() => setShowAbTestModal(true)}>
              <FlaskConical className="mr-1.5 h-4 w-4" />
              New A/B Test
            </Button>
          </div>
        </div>

        {/* Filters */}
        <div className="flex flex-wrap items-center gap-3">
          <div className="relative">
            <Search className="absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <input
              type="text"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && applyFilters()}
              placeholder="Search title, content, shortcut, category..."
              className="w-64 rounded-md border bg-background py-2 pl-9 pr-3 text-sm"
            />
          </div>
          <select
            value={pageFilter}
            onChange={(e) => {
              setPageFilter(e.target.value);
            }}
            className="rounded-md border bg-background px-3 py-2 text-sm"
          >
            <option value="">All Pages</option>
            {pages.map((p) => (
              <option key={p.id} value={p.id}>
                {p.page_name}
              </option>
            ))}
          </select>
          <select
            value={categoryFilter}
            onChange={(e) => setCategoryFilter(e.target.value)}
            className="rounded-md border bg-background px-3 py-2 text-sm"
          >
            <option value="">All Categories</option>
            {categories.map((c) => (
              <option key={c} value={c}>
                {c}
              </option>
            ))}
          </select>
          <select
            value={intentFilter}
            onChange={(e) => setIntentFilter(e.target.value)}
            className="rounded-md border bg-background px-3 py-2 text-sm"
          >
            <option value="">All Intents</option>
            {intents.map((i) => (
              <option key={i} value={i}>
                {INTENT_OPTIONS.find((opt) => opt.value === i)?.label ?? i}
              </option>
            ))}
          </select>
          <select
            value={approvalFilter}
            onChange={(e) => setApprovalFilter(e.target.value)}
            className="rounded-md border bg-background px-3 py-2 text-sm"
          >
            <option value="">All Approval Status</option>
            <option value="null">No Status (Legacy)</option>
            {approval_statuses.map((s) => (
              <option key={s} value={s}>
                {s.charAt(0).toUpperCase() + s.slice(1)}
              </option>
            ))}
          </select>
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={favoritesOnly}
              onChange={(e) => setFavoritesOnly(e.target.checked)}
              className="rounded"
            />
            <Star className="h-3.5 w-3.5 text-warning" />
            Favorites only
          </label>
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={activeOnly}
              onChange={(e) => setActiveOnly(e.target.checked)}
              className="rounded"
            />
            Active only
          </label>
          <Button onClick={applyFilters} size="sm" variant="outline">
            <Search className="mr-1.5 h-4 w-4" />
            Filter
          </Button>
        </div>

        {/* Stats */}
        <div className="flex gap-4 text-sm text-muted-foreground">
          <span>{templates.total} total templates</span>
          <span>{templates.data.filter((t) => t.is_active).length} active on this page</span>
        </div>

        {/* Usage Analytics */}
        <div className="space-y-4">
          <h2 className="flex items-center gap-2 text-lg font-semibold">
            <BarChart3 className="h-5 w-5" />
            Usage Analytics
          </h2>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Card>
              <CardHeader className="pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground">
                  Total Uses
                </CardTitle>
              </CardHeader>
              <CardContent>
                <div className="text-2xl font-bold">{analytics.total_uses}</div>
              </CardContent>
            </Card>
            <Card>
              <CardHeader className="pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground">
                  This Month
                </CardTitle>
              </CardHeader>
              <CardContent>
                <div className="text-2xl font-bold">{analytics.uses_this_month}</div>
              </CardContent>
            </Card>
            <Card>
              <CardHeader className="pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground">
                  Last 30 Days
                </CardTitle>
              </CardHeader>
              <CardContent>
                <div className="text-2xl font-bold">{analytics.uses_last_30_days}</div>
              </CardContent>
            </Card>
            <Card>
              <CardHeader className="pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground">
                  Most Used Template
                </CardTitle>
              </CardHeader>
              <CardContent>
                <div className="text-2xl font-bold">{analytics.top_templates[0]?.count ?? 0}</div>
                <p className="truncate text-xs text-muted-foreground">
                  {analytics.top_templates[0]?.title ?? 'No usage yet'}
                </p>
              </CardContent>
            </Card>
          </div>

          <div className="grid gap-4 lg:grid-cols-3">
            <Card className="lg:col-span-2">
              <CardHeader>
                <CardTitle className="text-sm font-medium">Daily Usage (Last 30 Days)</CardTitle>
              </CardHeader>
              <CardContent>
                <div className="h-64 w-full">
                  <ResponsiveContainer width="100%" height="100%">
                    <BarChart data={analytics.daily_usage}>
                      <CartesianGrid strokeDasharray="3 3" />
                      <XAxis
                        dataKey="date"
                        tickFormatter={(value: string) =>
                          new Date(value).toLocaleDateString(undefined, {
                            month: 'short',
                            day: 'numeric',
                          })
                        }
                        tick={{ fontSize: 10 }}
                      />
                      <YAxis allowDecimals={false} tick={{ fontSize: 10 }} />
                      <Tooltip
                        formatter={(value: number) => [`${value} uses`, 'Uses']}
                        labelFormatter={(label: string) => new Date(label).toLocaleDateString()}
                      />
                      <Bar dataKey="count" fill="currentColor" className="fill-primary" />
                    </BarChart>
                  </ResponsiveContainer>
                </div>
              </CardContent>
            </Card>

            <div className="space-y-4">
              <Card>
                <CardHeader>
                  <CardTitle className="text-sm font-medium">Top Templates</CardTitle>
                </CardHeader>
                <CardContent>
                  {analytics.top_templates.length > 0 ? (
                    <ul className="space-y-2 text-sm">
                      {analytics.top_templates.slice(0, 5).map((t) => (
                        <li key={t.id} className="flex items-center justify-between">
                          <span className="truncate pr-2" title={t.title ?? undefined}>
                            {t.title ?? 'Untitled'}
                          </span>
                          <Badge variant="secondary">{t.count}</Badge>
                        </li>
                      ))}
                    </ul>
                  ) : (
                    <p className="text-sm text-muted-foreground">No usage data yet.</p>
                  )}
                </CardContent>
              </Card>

              <Card>
                <CardHeader>
                  <CardTitle className="text-sm font-medium">Top Users</CardTitle>
                </CardHeader>
                <CardContent>
                  {analytics.top_users.length > 0 ? (
                    <ul className="space-y-2 text-sm">
                      {analytics.top_users.slice(0, 5).map((u) => (
                        <li key={u.id} className="flex items-center justify-between">
                          <span className="truncate pr-2">{u.name ?? 'Unknown'}</span>
                          <Badge variant="secondary">{u.count}</Badge>
                        </li>
                      ))}
                    </ul>
                  ) : (
                    <p className="text-sm text-muted-foreground">No usage data yet.</p>
                  )}
                </CardContent>
              </Card>
            </div>
          </div>
        </div>

        {/* Performance Metrics */}
        <div className="space-y-4">
          <h2 className="flex items-center gap-2 text-lg font-bold">
            <BarChart3 className="h-5 w-5 text-primary" />
            Performance Metrics
          </h2>

          {/* Approval + Trend Summary Cards */}
          <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
            <Card>
              <CardContent className="p-4">
                <p className="text-xs text-muted-foreground">Pending Approval</p>
                <p className="mt-1 text-2xl font-bold text-warning">
                  {performance.approval_stats.pending}
                </p>
              </CardContent>
            </Card>
            <Card>
              <CardContent className="p-4">
                <p className="text-xs text-muted-foreground">Rejection Rate</p>
                <p className="mt-1 text-2xl font-bold text-destructive">
                  {performance.rejection_rate}%
                </p>
              </CardContent>
            </Card>
            <Card>
              <CardContent className="p-4">
                <p className="text-xs text-muted-foreground">Avg Approval Time</p>
                <p className="mt-1 text-2xl font-bold">
                  {performance.avg_approval_time_seconds
                    ? `${Math.round(performance.avg_approval_time_seconds / 60)}m`
                    : '—'}
                </p>
              </CardContent>
            </Card>
            <Card>
              <CardContent className="p-4">
                <p className="text-xs text-muted-foreground">Usage Trend (7d)</p>
                <p
                  className={`mt-1 text-2xl font-bold ${
                    performance.usage_trend.direction === 'up'
                      ? 'text-green-500'
                      : performance.usage_trend.direction === 'down'
                        ? 'text-red-500'
                        : 'text-muted-foreground'
                  }`}
                >
                  {performance.usage_trend.direction === 'up'
                    ? '↑'
                    : performance.usage_trend.direction === 'down'
                      ? '↓'
                      : '→'}{' '}
                  {performance.usage_trend.percent_change}%
                </p>
                <p className="text-xs text-muted-foreground">
                  {performance.usage_trend.last_7_days} uses (prev:{' '}
                  {performance.usage_trend.prev_7_days})
                </p>
              </CardContent>
            </Card>
          </div>

          {/* Template Performance Table + Category/Intent Breakdown */}
          <div className="grid gap-4 lg:grid-cols-3">
            {/* Per-template performance */}
            <Card className="lg:col-span-2">
              <CardHeader>
                <CardTitle className="text-sm font-medium">Template Performance</CardTitle>
              </CardHeader>
              <CardContent>
                {performance.template_performance.length > 0 ? (
                  <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                      <thead>
                        <tr className="border-b text-left text-xs text-muted-foreground">
                          <th className="pb-2 pr-3">Template</th>
                          <th className="pb-2 pr-3 text-right">Uses</th>
                          <th className="pb-2 pr-3 text-right">Users</th>
                          <th className="pb-2 pr-3 text-right">Convs</th>
                          <th className="pb-2 pr-3 text-right">Resolved</th>
                          <th className="pb-2 text-right">Rate</th>
                        </tr>
                      </thead>
                      <tbody>
                        {performance.template_performance.slice(0, 10).map((t) => (
                          <tr key={t.id} className="border-b last:border-0">
                            <td className="py-2 pr-3">
                              <span className="truncate" title={t.title}>
                                {t.title}
                              </span>
                              {t.category && (
                                <span className="ml-1 text-xs text-info">[{t.category}]</span>
                              )}
                            </td>
                            <td className="py-2 pr-3 text-right">{t.total_uses}</td>
                            <td className="py-2 pr-3 text-right">{t.unique_users}</td>
                            <td className="py-2 pr-3 text-right">{t.unique_conversations}</td>
                            <td className="py-2 pr-3 text-right">{t.resolved_conversations}</td>
                            <td className="py-2 text-right font-medium">{t.resolution_rate}%</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                ) : (
                  <p className="text-sm text-muted-foreground">
                    No performance data yet. Use templates in conversations to see metrics.
                  </p>
                )}
              </CardContent>
            </Card>

            {/* Category + Intent breakdown */}
            <div className="space-y-4">
              <Card>
                <CardHeader>
                  <CardTitle className="text-sm font-medium">By Category</CardTitle>
                </CardHeader>
                <CardContent>
                  {performance.category_performance.length > 0 ? (
                    <ul className="space-y-2 text-sm">
                      {performance.category_performance.map((c) => (
                        <li key={c.category} className="flex items-center justify-between">
                          <span className="truncate pr-2">{c.category}</span>
                          <div className="flex items-center gap-2">
                            <Badge variant="outline" className="text-xs">
                              {c.template_count} templates
                            </Badge>
                            <Badge variant="secondary">{c.total_usage}</Badge>
                          </div>
                        </li>
                      ))}
                    </ul>
                  ) : (
                    <p className="text-sm text-muted-foreground">No categories yet.</p>
                  )}
                </CardContent>
              </Card>

              <Card>
                <CardHeader>
                  <CardTitle className="text-sm font-medium">By Intent</CardTitle>
                </CardHeader>
                <CardContent>
                  {performance.intent_performance.length > 0 ? (
                    <ul className="space-y-2 text-sm">
                      {performance.intent_performance.map((i) => (
                        <li key={i.intent} className="flex items-center justify-between">
                          <span className="truncate pr-2">
                            {INTENT_OPTIONS.find((opt) => opt.value === i.intent)?.label ??
                              i.intent}
                          </span>
                          <div className="flex items-center gap-2">
                            <Badge variant="outline" className="text-xs">
                              {i.template_count} templates
                            </Badge>
                            <Badge variant="secondary">{i.total_usage}</Badge>
                          </div>
                        </li>
                      ))}
                    </ul>
                  ) : (
                    <p className="text-sm text-muted-foreground">No intents yet.</p>
                  )}
                </CardContent>
              </Card>
            </div>
          </div>
        </div>

        {/* A/B Testing */}
        {ab_tests.length > 0 && (
          <div className="space-y-3">
            <h2 className="flex items-center gap-2 text-lg font-semibold">
              <FlaskConical className="h-5 w-5 text-primary" />
              A/B Tests
            </h2>
            {ab_tests.map((test) => (
              <Card key={test.id}>
                <CardContent className="p-4">
                  <div className="flex items-start justify-between gap-4">
                    <div className="min-w-0 flex-1">
                      <div className="flex items-center gap-2">
                        <h3 className="text-sm font-semibold">{test.name}</h3>
                        <Badge
                          className={
                            test.status === 'active'
                              ? 'bg-green-100 text-green-700 text-xs'
                              : test.status === 'completed'
                                ? 'bg-blue-100 text-blue-700 text-xs'
                                : test.status === 'paused'
                                  ? 'bg-amber-100 text-amber-700 text-xs'
                                  : 'bg-muted text-muted-foreground text-xs'
                          }
                        >
                          {test.status}
                        </Badge>
                        {test.winning_variant && (
                          <Badge className="bg-violet-100 text-violet-700 text-xs">
                            <Trophy className="mr-1 h-3 w-3" />
                            Winner: {test.winning_variant.label}
                          </Badge>
                        )}
                      </div>
                      {test.description && (
                        <p className="mt-1 text-xs text-muted-foreground">{test.description}</p>
                      )}
                      <div className="mt-2 flex flex-wrap gap-2">
                        {test.variants.map((v) => (
                          <div key={v.id} className="rounded-md border px-2.5 py-1.5 text-xs">
                            <div className="flex items-center gap-1.5">
                              <span className="font-semibold">{v.label}</span>
                              <span className="text-muted-foreground">
                                {v.template_title ?? '—'}
                              </span>
                            </div>
                            <div className="mt-1 flex items-center gap-2 text-muted-foreground">
                              <span>{v.uses} uses</span>
                              <span>·</span>
                              <span>{v.conversion_rate}% conv</span>
                              <span>·</span>
                              <span>{v.resolution_rate}% res</span>
                            </div>
                          </div>
                        ))}
                      </div>
                    </div>
                    <div className="flex shrink-0 flex-col gap-1.5">
                      <Button
                        size="sm"
                        variant="outline"
                        onClick={() => viewAbTestResults(test.id)}
                        disabled={abTestLoading}
                      >
                        <BarChart3 className="mr-1 h-3.5 w-3.5" />
                        Results
                      </Button>
                      {test.status !== 'completed' && (
                        <>
                          <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                              updateAbTestStatus(
                                test.id,
                                test.status === 'active' ? 'paused' : 'active'
                              )
                            }
                          >
                            {test.status === 'active' ? (
                              <>
                                <Pause className="mr-1 h-3.5 w-3.5" />
                                Pause
                              </>
                            ) : (
                              <>
                                <Play className="mr-1 h-3.5 w-3.5" />
                                Activate
                              </>
                            )}
                          </Button>
                          <Button size="sm" variant="outline" onClick={() => endAbTest(test.id)}>
                            <Trophy className="mr-1 h-3.5 w-3.5" />
                            End Test
                          </Button>
                        </>
                      )}
                    </div>
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
        )}

        {/* A/B Test Results Modal */}
        {abTestResults && (
          <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
            onClick={() => setAbTestResults(null)}
          >
            <div
              className="max-h-[80vh] w-full max-w-2xl overflow-y-auto rounded-lg bg-background p-6 shadow-lg"
              onClick={(e) => e.stopPropagation()}
            >
              <div className="flex items-center justify-between">
                <h2 className="text-lg font-semibold">
                  A/B Test Results: {abTestResults.test.name}
                </h2>
                <Button size="sm" variant="ghost" onClick={() => setAbTestResults(null)}>
                  <X className="h-4 w-4" />
                </Button>
              </div>

              <div className="mt-4 grid grid-cols-3 gap-3">
                <div className="rounded-md border p-3 text-center">
                  <p className="text-xs text-muted-foreground">Total Impressions</p>
                  <p className="text-xl font-bold">{abTestResults.summary.total_impressions}</p>
                </div>
                <div className="rounded-md border p-3 text-center">
                  <p className="text-xs text-muted-foreground">Total Uses</p>
                  <p className="text-xl font-bold">{abTestResults.summary.total_uses}</p>
                </div>
                <div className="rounded-md border p-3 text-center">
                  <p className="text-xs text-muted-foreground">Total Resolved</p>
                  <p className="text-xl font-bold">{abTestResults.summary.total_resolved}</p>
                </div>
              </div>

              <div className="mt-4 grid grid-cols-2 gap-3">
                <div className="rounded-md border p-3 text-center">
                  <p className="text-xs text-muted-foreground">Overall Conversion Rate</p>
                  <p className="text-xl font-bold">
                    {abTestResults.summary.overall_conversion_rate}%
                  </p>
                </div>
                <div className="rounded-md border p-3 text-center">
                  <p className="text-xs text-muted-foreground">Overall Resolution Rate</p>
                  <p className="text-xl font-bold">
                    {abTestResults.summary.overall_resolution_rate}%
                  </p>
                </div>
              </div>

              {abTestResults.summary.best_variant && (
                <div className="mt-4 rounded-md border border-violet-200 bg-violet-50 p-3 dark:bg-violet-950/20">
                  <p className="flex items-center gap-1.5 text-sm font-medium">
                    <Trophy className="h-4 w-4 text-violet-600" />
                    Best Variant: {abTestResults.summary.best_variant.label} —{' '}
                    {abTestResults.summary.best_variant.template_title}
                  </p>
                  <p className="mt-1 text-xs text-muted-foreground">
                    Resolution rate: {abTestResults.summary.best_variant.resolution_rate}% ·
                    Conversion rate: {abTestResults.summary.best_variant.conversion_rate}%
                  </p>
                </div>
              )}

              <div className="mt-4">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b text-left text-xs text-muted-foreground">
                      <th className="pb-2">Variant</th>
                      <th className="pb-2">Template</th>
                      <th className="pb-2 text-right">Impr.</th>
                      <th className="pb-2 text-right">Uses</th>
                      <th className="pb-2 text-right">Conv.</th>
                      <th className="pb-2 text-right">Res.</th>
                    </tr>
                  </thead>
                  <tbody>
                    {abTestResults.variants.map((v) => (
                      <tr key={v.id} className="border-b">
                        <td className="py-2 font-semibold">{v.label}</td>
                        <td className="py-2 truncate">{v.template_title ?? '—'}</td>
                        <td className="py-2 text-right">{v.impressions}</td>
                        <td className="py-2 text-right">{v.uses}</td>
                        <td className="py-2 text-right">{v.conversion_rate}%</td>
                        <td className="py-2 text-right">{v.resolution_rate}%</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        )}

        {/* A/B Test Create Modal */}
        {showAbTestModal && (
          <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
            onClick={() => setShowAbTestModal(false)}
          >
            <div
              className="max-h-[80vh] w-full max-w-lg overflow-y-auto rounded-lg bg-background p-6 shadow-lg"
              onClick={(e) => e.stopPropagation()}
            >
              <div className="flex items-center justify-between">
                <h2 className="text-lg font-semibold">Create A/B Test</h2>
                <Button size="sm" variant="ghost" onClick={() => setShowAbTestModal(false)}>
                  <X className="h-4 w-4" />
                </Button>
              </div>

              <div className="mt-4 space-y-4">
                <div>
                  <label className="text-sm font-medium">Test Name</label>
                  <input
                    type="text"
                    value={abTestForm.name}
                    onChange={(e) => setAbTestForm((p) => ({ ...p, name: e.target.value }))}
                    className="mt-1 w-full rounded-md border px-3 py-2 text-sm"
                    placeholder="e.g. Greeting variants — July"
                  />
                </div>
                <div>
                  <label className="text-sm font-medium">Description (optional)</label>
                  <textarea
                    value={abTestForm.description}
                    onChange={(e) => setAbTestForm((p) => ({ ...p, description: e.target.value }))}
                    className="mt-1 w-full rounded-md border px-3 py-2 text-sm"
                    rows={2}
                    placeholder="What are you testing?"
                  />
                </div>
                <div>
                  <label className="text-sm font-medium">
                    Select Templates ({abTestForm.template_ids.length} selected, min 2)
                  </label>
                  <div className="mt-1 max-h-48 space-y-1 overflow-y-auto rounded-md border p-2">
                    {templates.data.map((t) => (
                      <label
                        key={t.id}
                        className="flex cursor-pointer items-center gap-2 rounded px-2 py-1 text-sm hover:bg-muted/50"
                      >
                        <input
                          type="checkbox"
                          checked={abTestForm.template_ids.includes(t.id)}
                          onChange={() => toggleAbTestTemplate(t.id)}
                        />
                        <span className="truncate">{t.title}</span>
                        {t.intent && (
                          <Badge variant="outline" className="text-[10px]">
                            {t.intent.replace(/_/g, ' ')}
                          </Badge>
                        )}
                      </label>
                    ))}
                  </div>
                </div>
                {abTestForm.template_ids.length >= 2 && (
                  <div>
                    <label className="text-sm font-medium">Traffic Split</label>
                    <div className="mt-1 space-y-1">
                      {abTestForm.template_ids.map((tid, i) => {
                        const t = templates.data.find((tp) => tp.id === tid);
                        return (
                          <div key={tid} className="flex items-center gap-2 text-sm">
                            <span className="w-6 font-semibold">{String.fromCharCode(65 + i)}</span>
                            <span className="flex-1 truncate">{t?.title ?? '—'}</span>
                            <input
                              type="number"
                              min={1}
                              max={100}
                              value={abTestForm.weights[i] ?? 50}
                              onChange={(e) => {
                                const val = parseInt(e.target.value) || 1;
                                setAbTestForm((prev) => {
                                  const weights = [...prev.weights];
                                  weights[i] = val;
                                  return { ...prev, weights };
                                });
                              }}
                              className="w-16 rounded-md border px-2 py-1 text-sm"
                            />
                            <span className="text-xs text-muted-foreground">%</span>
                          </div>
                        );
                      })}
                    </div>
                  </div>
                )}
                <div className="flex justify-end gap-2">
                  <Button variant="outline" onClick={() => setShowAbTestModal(false)}>
                    Cancel
                  </Button>
                  <Button
                    onClick={createAbTest}
                    disabled={
                      abTestSaving || abTestForm.template_ids.length < 2 || !abTestForm.name.trim()
                    }
                  >
                    {abTestSaving ? 'Creating...' : 'Create Test'}
                  </Button>
                </div>
              </div>
            </div>
          </div>
        )}

        {/* Template List */}
        {templates.data.length > 0 ? (
          <div className="space-y-3">
            {templates.data.map((template) => (
              <Card key={template.id}>
                <CardContent className="p-4">
                  <div className="flex items-start justify-between gap-4">
                    <div className="min-w-0 flex-1">
                      <div className="flex items-center gap-2">
                        <h3 className="text-sm font-semibold">{template.title}</h3>
                        {template.shortcut && (
                          <Badge variant="outline" className="font-mono text-xs">
                            /{template.shortcut}
                          </Badge>
                        )}
                        {!template.is_active && (
                          <Badge className="bg-muted text-muted-foreground text-xs">Inactive</Badge>
                        )}
                        {template.facebook_page && (
                          <Badge variant="secondary" className="text-xs">
                            {template.facebook_page.page_name}
                          </Badge>
                        )}
                        {template.category && (
                          <Badge variant="outline" className="text-xs text-info">
                            {template.category}
                          </Badge>
                        )}
                        {template.intent && (
                          <Badge variant="outline" className="text-xs text-warning">
                            {INTENT_OPTIONS.find((opt) => opt.value === template.intent)?.label ??
                              template.intent}
                          </Badge>
                        )}
                        {template.allowed_roles && template.allowed_roles.length > 0 && (
                          <Badge variant="outline" className="text-xs text-destructive">
                            {template.allowed_roles.join(', ')}
                          </Badge>
                        )}
                        {template.shared_pages && template.shared_pages.length > 0 && (
                          <Badge variant="outline" className="text-xs text-success">
                            Shared: {template.shared_pages.map((p) => p.page_name).join(', ')}
                          </Badge>
                        )}
                        {template.approval_status === 'pending' && (
                          <Badge variant="outline" className="text-xs text-warning">
                            Pending Approval
                          </Badge>
                        )}
                        {template.approval_status === 'rejected' && (
                          <Badge variant="outline" className="text-xs text-destructive">
                            Rejected
                          </Badge>
                        )}
                      </div>
                      <p className="mt-1 line-clamp-2 text-sm text-muted-foreground">
                        {template.content}
                      </p>
                      {template.variables && template.variables.length > 0 && (
                        <div className="mt-1.5 flex flex-wrap gap-1">
                          {template.variables.map((v) => (
                            <Badge
                              key={v}
                              variant="outline"
                              className="font-mono text-[10px] text-primary"
                            >
                              {v}
                            </Badge>
                          ))}
                        </div>
                      )}
                      <div className="mt-2 flex items-center gap-3 text-xs text-muted-foreground">
                        <span>Used {template.usage_count} times</span>
                        {template.creator && <span>by {template.creator.name}</span>}
                        <span>{new Date(template.created_at).toLocaleDateString()}</span>
                        {template.approval_status === 'rejected' && template.rejection_reason && (
                          <span className="text-destructive" title={template.rejection_reason}>
                            Rejected: {template.rejection_reason}
                          </span>
                        )}
                        {template.approval_status === 'approved' && template.approver && (
                          <span title={`Approved by ${template.approver.name}`}>
                            Approved by {template.approver.name}
                          </span>
                        )}
                      </div>
                    </div>
                    <div className="flex shrink-0 gap-1">
                      <Button
                        size="icon"
                        variant="ghost"
                        className="h-8 w-8"
                        onClick={() => toggleFavorite(template)}
                        title={template.is_favorited ? 'Remove from favorites' : 'Add to favorites'}
                      >
                        <Star
                          className={`h-4 w-4 ${template.is_favorited ? 'fill-warning text-warning' : 'text-muted-foreground'}`}
                        />
                      </Button>
                      <Button
                        size="icon"
                        variant="ghost"
                        className="h-8 w-8"
                        onClick={() => copyContent(template.content)}
                        title="Copy content"
                      >
                        <Copy className="h-4 w-4" />
                      </Button>
                      <Button
                        size="icon"
                        variant="ghost"
                        className="h-8 w-8"
                        onClick={() => openVersionHistory(template.id)}
                        title="Version history"
                      >
                        <History className="h-4 w-4" />
                      </Button>
                      <Button
                        size="icon"
                        variant="ghost"
                        className="h-8 w-8"
                        onClick={() => openEdit(template)}
                        title="Edit"
                      >
                        <Pencil className="h-4 w-4" />
                      </Button>
                      <Button
                        size="icon"
                        variant="ghost"
                        className="h-8 w-8"
                        onClick={() => toggleActive(template)}
                        title={template.is_active ? 'Deactivate' : 'Activate'}
                      >
                        <Power
                          className={`h-4 w-4 ${template.is_active ? 'text-green-500' : 'text-muted-foreground'}`}
                        />
                      </Button>
                      {template.approval_status === 'pending' && (
                        <>
                          <Button
                            size="icon"
                            variant="ghost"
                            className="h-8 w-8 text-green-600"
                            onClick={() => approveTemplate(template)}
                            title="Approve"
                          >
                            <Check className="h-4 w-4" />
                          </Button>
                          <Button
                            size="icon"
                            variant="ghost"
                            className="h-8 w-8 text-red-500"
                            onClick={() => rejectTemplate(template)}
                            title="Reject"
                          >
                            <XCircle className="h-4 w-4" />
                          </Button>
                        </>
                      )}
                      {template.approval_status === 'rejected' && (
                        <Button
                          size="icon"
                          variant="ghost"
                          className="h-8 w-8 text-green-600"
                          onClick={() => approveTemplate(template)}
                          title="Approve"
                        >
                          <Check className="h-4 w-4" />
                        </Button>
                      )}
                      <Button
                        size="icon"
                        variant="ghost"
                        className="h-8 w-8 text-red-500"
                        onClick={() => confirmDelete(template.id)}
                        title="Delete"
                      >
                        <Trash2 className="h-4 w-4" />
                      </Button>
                    </div>
                  </div>
                </CardContent>
              </Card>
            ))}

            {/* Pagination */}
            {templates.last_page > 1 && (
              <div className="flex items-center justify-center gap-2">
                {templates.links.map((link, i) => (
                  <Button
                    key={i}
                    size="sm"
                    variant={link.active ? 'default' : 'outline'}
                    disabled={!link.url}
                    onClick={() => {
                      if (link.url) {
                        router.get(link.url, {}, { preserveScroll: true, preserveState: true });
                      }
                    }}
                    dangerouslySetInnerHTML={{ __html: link.label }}
                  />
                ))}
              </div>
            )}
          </div>
        ) : (
          <Card>
            <CardContent className="flex flex-col items-center justify-center py-12">
              <MessageSquare className="mb-3 h-12 w-12 text-muted-foreground" />
              <p className="text-sm text-muted-foreground">
                No reply templates yet. Create one to speed up your conversation responses.
              </p>
              <Button onClick={openCreate} size="sm" className="mt-3">
                <Plus className="mr-1.5 h-4 w-4" />
                Create Template
              </Button>
            </CardContent>
          </Card>
        )}
      </div>

      {/* Create/Edit Modal */}
      {showModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
          <Card className="w-full max-w-2xl">
            <CardContent className="p-6">
              <div className="mb-4 flex items-center justify-between">
                <h2 className="text-lg font-bold">{editing ? 'Edit Template' : 'New Template'}</h2>
                <Button
                  size="icon"
                  variant="ghost"
                  className="h-8 w-8"
                  onClick={() => setShowModal(false)}
                >
                  <X className="h-4 w-4" />
                </Button>
              </div>

              {error && (
                <div className="mb-4 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                  {error}
                </div>
              )}

              <div className="space-y-4">
                <div>
                  <label className="mb-1 block text-sm font-medium">Title</label>
                  <input
                    type="text"
                    value={form.title}
                    onChange={(e) => setForm({ ...form, title: e.target.value })}
                    placeholder="e.g. Order Confirmation"
                    className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                  />
                </div>

                <div>
                  <label className="mb-1 block text-sm font-medium">Content</label>
                  <textarea
                    value={form.content}
                    onChange={(e) => setForm({ ...form, content: e.target.value })}
                    placeholder="Hi {customer_name}, your order {order_number} has been confirmed..."
                    rows={6}
                    className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                  />
                  {/* Detected variables */}
                  {form.content &&
                    (() => {
                      const matches = form.content.match(/\{\w+\}/g);
                      if (!matches || matches.length === 0) return null;
                      const unique = [...new Set(matches)];
                      return (
                        <div className="mt-1.5 flex flex-wrap items-center gap-1">
                          <span className="text-xs text-muted-foreground">Detected variables:</span>
                          {unique.map((v) => (
                            <Badge
                              key={v}
                              variant="outline"
                              className="font-mono text-[10px] text-primary"
                            >
                              {v}
                            </Badge>
                          ))}
                        </div>
                      );
                    })()}
                  {/* Variable reference */}
                  <details className="mt-2">
                    <summary className="cursor-pointer text-xs text-muted-foreground">
                      Available variables (click to insert)
                    </summary>
                    <div className="mt-2 grid gap-1 sm:grid-cols-2">
                      {AVAILABLE_VARIABLES.map((v) => (
                        <button
                          key={v.key}
                          type="button"
                          onClick={() => {
                            setForm({ ...form, content: form.content + v.key });
                          }}
                          className="flex items-center gap-2 rounded px-2 py-1 text-left text-xs hover:bg-muted/50"
                        >
                          <span className="font-mono text-primary">{v.key}</span>
                          <span className="text-muted-foreground">{v.desc}</span>
                        </button>
                      ))}
                    </div>
                  </details>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                  <div>
                    <label className="mb-1 block text-sm font-medium">Category</label>
                    <input
                      type="text"
                      value={form.category}
                      onChange={(e) => setForm({ ...form, category: e.target.value })}
                      placeholder="e.g. Sales, Support, Logistics"
                      list="category-suggestions"
                      className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                    />
                    <datalist id="category-suggestions">
                      {categories.map((c) => (
                        <option key={c} value={c} />
                      ))}
                    </datalist>
                    <p className="mt-1 text-xs text-muted-foreground">
                      Free-text grouping for templates
                    </p>
                  </div>

                  <div>
                    <label className="mb-1 block text-sm font-medium">Intent</label>
                    <select
                      value={form.intent}
                      onChange={(e) => setForm({ ...form, intent: e.target.value })}
                      className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                    >
                      <option value="">No intent</option>
                      {INTENT_OPTIONS.map((opt) => (
                        <option key={opt.value} value={opt.value}>
                          {opt.label}
                        </option>
                      ))}
                    </select>
                    <p className="mt-1 text-xs text-muted-foreground">
                      Classify the purpose of this template
                    </p>
                  </div>
                </div>

                <div>
                  <label className="mb-1 block text-sm font-medium">Allowed Roles</label>
                  <p className="mb-2 text-xs text-muted-foreground">
                    Leave empty to allow all roles. Select specific roles to restrict access.
                  </p>
                  <div className="flex flex-wrap gap-3">
                    {roles.map((role) => (
                      <label key={role} className="flex items-center gap-1.5 text-sm">
                        <input
                          type="checkbox"
                          checked={form.allowed_roles.includes(role)}
                          onChange={(e) => {
                            if (e.target.checked) {
                              setForm({ ...form, allowed_roles: [...form.allowed_roles, role] });
                            } else {
                              setForm({
                                ...form,
                                allowed_roles: form.allowed_roles.filter((r) => r !== role),
                              });
                            }
                          }}
                          className="rounded"
                        />
                        <span className="capitalize">{role}</span>
                      </label>
                    ))}
                  </div>
                </div>

                <div>
                  <label className="mb-1 block text-sm font-medium">Share with Pages</label>
                  <p className="mb-2 text-xs text-muted-foreground">
                    Select pages to share this template with. The template will be available on
                    those pages in addition to its assigned page.
                  </p>
                  <div className="flex flex-wrap gap-3">
                    {pages.map((p) => (
                      <label key={p.id} className="flex items-center gap-1.5 text-sm">
                        <input
                          type="checkbox"
                          checked={form.shared_page_ids.includes(p.id)}
                          onChange={(e) => {
                            if (e.target.checked) {
                              setForm({
                                ...form,
                                shared_page_ids: [...form.shared_page_ids, p.id],
                              });
                            } else {
                              setForm({
                                ...form,
                                shared_page_ids: form.shared_page_ids.filter((id) => id !== p.id),
                              });
                            }
                          }}
                          className="rounded"
                        />
                        <span>{p.page_name}</span>
                      </label>
                    ))}
                  </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                  <div>
                    <label className="mb-1 block text-sm font-medium">Shortcut</label>
                    <input
                      type="text"
                      value={form.shortcut}
                      onChange={(e) => setForm({ ...form, shortcut: e.target.value })}
                      placeholder="e.g. confirm"
                      className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                    />
                    <p className="mt-1 text-xs text-muted-foreground">
                      Type /shortcut in reply to insert
                    </p>
                  </div>

                  <div>
                    <label className="mb-1 block text-sm font-medium">Facebook Page</label>
                    <select
                      value={form.facebook_page_id}
                      onChange={(e) => setForm({ ...form, facebook_page_id: e.target.value })}
                      className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                    >
                      <option value="">All Pages</option>
                      {pages.map((p) => (
                        <option key={p.id} value={p.id}>
                          {p.page_name}
                        </option>
                      ))}
                    </select>
                  </div>
                </div>

                <label className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={form.is_active}
                    onChange={(e) => setForm({ ...form, is_active: e.target.checked })}
                    className="rounded"
                  />
                  Active
                </label>
              </div>

              <div className="mt-6 flex justify-end gap-2">
                <Button variant="outline" onClick={() => setShowModal(false)}>
                  Cancel
                </Button>
                <Button onClick={save} disabled={saving || !form.title || !form.content}>
                  {saving ? 'Saving...' : editing ? 'Update' : 'Create'}
                </Button>
              </div>
            </CardContent>
          </Card>
        </div>
      )}

      {/* Delete Confirmation */}
      {deleteId && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
          <Card className="w-full max-w-md">
            <CardContent className="p-6">
              <h2 className="text-lg font-bold">Delete Template?</h2>
              <p className="mt-2 text-sm text-muted-foreground">
                This template will be archived. You can restore it later if needed.
              </p>
              <div className="mt-6 flex justify-end gap-2">
                <Button variant="outline" onClick={() => setDeleteId(null)}>
                  Cancel
                </Button>
                <Button variant="destructive" onClick={doDelete}>
                  Delete
                </Button>
              </div>
            </CardContent>
          </Card>
        </div>
      )}

      {/* Version History Modal */}
      {versionTemplateId && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
          <Card className="w-full max-w-2xl">
            <CardContent className="p-6">
              <div className="mb-4 flex items-center justify-between">
                <h2 className="flex items-center gap-2 text-lg font-bold">
                  <History className="h-5 w-5" />
                  Version History
                </h2>
                <Button
                  size="icon"
                  variant="ghost"
                  className="h-8 w-8"
                  onClick={() => {
                    setVersionTemplateId(null);
                    setVersionDetail(null);
                  }}
                >
                  <X className="h-4 w-4" />
                </Button>
              </div>

              <div className="grid gap-4 lg:grid-cols-2">
                {/* Version list */}
                <div className="max-h-96 space-y-2 overflow-y-auto">
                  {versions.length > 0 ? (
                    versions.map((v) => (
                      <button
                        key={v.id}
                        onClick={() => viewVersion(v.id)}
                        className={`w-full rounded-md border p-3 text-left text-sm transition-colors hover:bg-muted/50 ${
                          versionDetail?.id === v.id ? 'border-primary bg-muted/50' : ''
                        }`}
                      >
                        <div className="flex items-center justify-between">
                          <span className="font-medium">v{v.version_number}</span>
                          <span className="text-xs text-muted-foreground">
                            {new Date(v.created_at).toLocaleString()}
                          </span>
                        </div>
                        <div className="mt-1 truncate text-xs text-muted-foreground">
                          {v.change_summary || v.title}
                        </div>
                        {v.edited_by && (
                          <div className="mt-0.5 text-xs text-muted-foreground">
                            by {v.edited_by}
                          </div>
                        )}
                      </button>
                    ))
                  ) : (
                    <p className="text-sm text-muted-foreground">
                      No version history yet. Versions are created when a template is edited.
                    </p>
                  )}
                </div>

                {/* Version detail */}
                <div className="max-h-96 overflow-y-auto">
                  {versionDetail ? (
                    <div className="space-y-3">
                      <div>
                        <span className="text-xs font-medium text-muted-foreground">
                          Version {versionDetail.version_number}
                        </span>
                        <h3 className="text-sm font-semibold">{versionDetail.title}</h3>
                        {versionDetail.change_summary && (
                          <p className="text-xs text-muted-foreground">
                            {versionDetail.change_summary}
                          </p>
                        )}
                      </div>
                      <div className="rounded-md border bg-muted/30 p-3">
                        <p className="whitespace-pre-wrap text-sm">{versionDetail.content}</p>
                      </div>
                      <Button
                        size="sm"
                        onClick={() => restoreVersion(versionDetail.id)}
                        disabled={restoring}
                      >
                        <RotateCcw className="mr-1.5 h-4 w-4" />
                        {restoring ? 'Restoring...' : 'Restore this version'}
                      </Button>
                    </div>
                  ) : (
                    <div className="flex h-full items-center justify-center">
                      <p className="text-sm text-muted-foreground">
                        Select a version to view its content.
                      </p>
                    </div>
                  )}
                </div>
              </div>
            </CardContent>
          </Card>
        </div>
      )}
    </AppLayout>
  );
}
