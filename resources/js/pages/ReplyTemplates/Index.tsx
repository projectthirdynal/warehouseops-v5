import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Plus, Search, Pencil, Trash2, Power, MessageSquare, Copy, X } from 'lucide-react';

interface FacebookPage {
  id: number;
  page_name: string;
}

interface ReplyTemplate {
  id: number;
  title: string;
  content: string;
  shortcut: string | null;
  facebook_page_id: number | null;
  is_active: boolean;
  usage_count: number;
  created_at: string;
  updated_at: string;
  facebook_page?: { id: number; page_name: string } | null;
  creator?: { id: number; name: string } | null;
}

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

interface Props {
  templates: PaginatedTemplates;
  pages: FacebookPage[];
  filters: {
    search: string;
    page_id: string;
    active_only: boolean;
  };
}

export default function ReplyTemplatesIndex({ templates, pages, filters }: Props) {
  const [search, setSearch] = useState(filters.search);
  const [pageFilter, setPageFilter] = useState(filters.page_id);
  const [activeOnly, setActiveOnly] = useState(filters.active_only);
  const [showModal, setShowModal] = useState(false);
  const [editing, setEditing] = useState<ReplyTemplate | null>(null);
  const [form, setForm] = useState({
    title: '',
    content: '',
    shortcut: '',
    facebook_page_id: '',
    is_active: true,
  });
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [deleteId, setDeleteId] = useState<number | null>(null);

  const applyFilters = () => {
    router.get(
      '/shop/reply-templates',
      {
        search: search || undefined,
        page_id: pageFilter || undefined,
        active_only: activeOnly,
      },
      { preserveScroll: true, preserveState: true }
    );
  };

  const openCreate = () => {
    setEditing(null);
    setForm({ title: '', content: '', shortcut: '', facebook_page_id: '', is_active: true });
    setError(null);
    setShowModal(true);
  };

  const openEdit = (template: ReplyTemplate) => {
    setEditing(template);
    setForm({
      title: template.title,
      content: template.content,
      shortcut: template.shortcut ?? '',
      facebook_page_id: template.facebook_page_id?.toString() ?? '',
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
      shortcut: form.shortcut || null,
      facebook_page_id: form.facebook_page_id || null,
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

  const copyContent = (content: string) => {
    navigator.clipboard.writeText(content);
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
              placeholder="Search title, content, shortcut..."
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
                      </div>
                      <p className="mt-1 line-clamp-2 text-sm text-muted-foreground">
                        {template.content}
                      </p>
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
                        onClick={() => copyContent(template.content)}
                        title="Copy content"
                      >
                        <Copy className="h-4 w-4" />
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
    </AppLayout>
  );
}
