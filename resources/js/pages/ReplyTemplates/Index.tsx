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

interface Props {
  templates: PaginatedTemplates;
  pages: FacebookPage[];
  categories: string[];
  intents: string[];
  roles: string[];
  analytics: UsageAnalytics;
  filters: {
    search: string;
    page_id: string;
    category: string;
    intent: string;
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
  filters,
}: Props) {
  const [search, setSearch] = useState(filters.search);
  const [pageFilter, setPageFilter] = useState(filters.page_id);
  const [categoryFilter, setCategoryFilter] = useState(filters.category);
  const [intentFilter, setIntentFilter] = useState(filters.intent);
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

  const applyFilters = () => {
    router.get(
      '/shop/reply-templates',
      {
        search: search || undefined,
        page_id: pageFilter || undefined,
        category: categoryFilter || undefined,
        intent: intentFilter || undefined,
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
