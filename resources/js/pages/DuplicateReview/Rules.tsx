import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { AlertTriangle, ArrowLeft, Plus, Pencil, Trash2, Power, Save, X } from 'lucide-react';
import { formatDateTime } from '@/lib/utils';

interface Rule {
  id: number;
  name: string;
  type: string;
  match_method: string | null;
  is_enabled: boolean;
  priority: number;
  config: Record<string, unknown> | null;
  description: string | null;
  created_by: string | null;
  updated_by: string | null;
  created_at: string;
  updated_at: string;
}

interface Props {
  rules: Rule[];
}

const typeLabels: Record<string, string> = {
  order: 'Order',
  customer: 'Customer',
  conversation: 'Conversation',
};

const typeColors: Record<string, string> = {
  order: 'bg-blue-100 text-blue-700',
  customer: 'bg-purple-100 text-purple-700',
  conversation: 'bg-green-100 text-green-700',
};

const defaultConfig: Record<string, string> = {
  time_window_hours: '72',
  name_threshold: '80',
  address_threshold: '0.6',
  limit: '20',
};

export default function DuplicateReviewRules({ rules }: Props) {
  const [showForm, setShowForm] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [saving, setSaving] = useState(false);
  const [form, setForm] = useState({
    name: '',
    type: 'order',
    match_method: '',
    is_enabled: true,
    priority: 0,
    description: '',
    config: { ...defaultConfig },
  });

  const csrf = () =>
    document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

  const resetForm = () => {
    setForm({
      name: '',
      type: 'order',
      match_method: '',
      is_enabled: true,
      priority: 0,
      description: '',
      config: { ...defaultConfig },
    });
    setEditingId(null);
    setShowForm(false);
  };

  const startEdit = (rule: Rule) => {
    setEditingId(rule.id);
    setShowForm(true);
    setForm({
      name: rule.name,
      type: rule.type,
      match_method: rule.match_method ?? '',
      is_enabled: rule.is_enabled,
      priority: rule.priority,
      description: rule.description ?? '',
      config: {
        ...defaultConfig,
        ...(rule.config as Record<string, string> | null),
      },
    });
  };

  const save = async () => {
    setSaving(true);
    try {
      const configObj: Record<string, number | string> = {};
      if (form.config.time_window_hours)
        configObj.time_window_hours = parseInt(form.config.time_window_hours);
      if (form.config.name_threshold)
        configObj.name_threshold = parseFloat(form.config.name_threshold);
      if (form.config.address_threshold)
        configObj.address_threshold = parseFloat(form.config.address_threshold);
      if (form.config.limit) configObj.limit = parseInt(form.config.limit);

      const payload = {
        name: form.name,
        type: form.type,
        match_method: form.match_method || null,
        is_enabled: form.is_enabled,
        priority: form.priority,
        description: form.description || null,
        config: configObj,
      };

      if (editingId) {
        await fetch(`/api/duplicate-check/rules/${editingId}`, {
          method: 'PUT',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrf(),
          },
          body: JSON.stringify(payload),
        });
      } else {
        await fetch('/api/duplicate-check/rules', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrf(),
          },
          body: JSON.stringify(payload),
        });
      }
      router.reload({ only: ['rules'] });
      resetForm();
    } catch {
      alert('Failed to save rule.');
    } finally {
      setSaving(false);
    }
  };

  const toggle = async (id: number) => {
    try {
      await fetch(`/api/duplicate-check/rules/${id}/toggle`, {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'X-CSRF-TOKEN': csrf(),
        },
      });
      router.reload({ only: ['rules'] });
    } catch {
      alert('Failed to toggle rule.');
    }
  };

  const remove = async (id: number) => {
    if (!confirm('Delete this rule? This cannot be undone.')) return;
    try {
      await fetch(`/api/duplicate-check/rules/${id}`, {
        method: 'DELETE',
        headers: {
          Accept: 'application/json',
          'X-CSRF-TOKEN': csrf(),
        },
      });
      router.reload({ only: ['rules'] });
    } catch {
      alert('Failed to delete rule.');
    }
  };

  return (
    <AppLayout>
      <div className="space-y-6 p-6">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="flex items-center gap-2 text-2xl font-bold">
              <AlertTriangle className="h-7 w-7 text-warning" />
              Detection Rules
            </h1>
            <p className="mt-1 text-sm text-muted-foreground">
              Configure how duplicate detection runs for orders, customers, and conversations.
            </p>
          </div>
          <div className="flex gap-2">
            <Link href="/shop/duplicate-review">
              <Button variant="outline" size="sm">
                <ArrowLeft className="mr-1.5 h-4 w-4" />
                Back to Queue
              </Button>
            </Link>
            {!showForm && (
              <Button
                size="sm"
                onClick={() => {
                  resetForm();
                  setShowForm(true);
                }}
              >
                <Plus className="mr-1.5 h-4 w-4" />
                Add Rule
              </Button>
            )}
          </div>
        </div>

        {showForm && (
          <Card>
            <CardHeader>
              <CardTitle className="text-base">
                {editingId ? 'Edit Rule' : 'New Detection Rule'}
              </CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="grid gap-4 sm:grid-cols-2">
                <div>
                  <label className="mb-1 block text-xs font-medium">Name</label>
                  <input
                    type="text"
                    className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                    value={form.name}
                    onChange={(e) => setForm({ ...form, name: e.target.value })}
                    placeholder="e.g. Order phone+product 72h"
                  />
                </div>
                <div>
                  <label className="mb-1 block text-xs font-medium">Type</label>
                  <select
                    className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                    value={form.type}
                    onChange={(e) => setForm({ ...form, type: e.target.value })}
                  >
                    <option value="order">Order</option>
                    <option value="customer">Customer</option>
                    <option value="conversation">Conversation</option>
                  </select>
                </div>
                <div>
                  <label className="mb-1 block text-xs font-medium">Match Method</label>
                  <input
                    type="text"
                    className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                    value={form.match_method}
                    onChange={(e) => setForm({ ...form, match_method: e.target.value })}
                    placeholder="e.g. phone, psid, name, fuzzy"
                  />
                </div>
                <div>
                  <label className="mb-1 block text-xs font-medium">
                    Priority (lower runs first)
                  </label>
                  <input
                    type="number"
                    className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                    value={form.priority}
                    onChange={(e) => setForm({ ...form, priority: parseInt(e.target.value) || 0 })}
                  />
                </div>
              </div>

              <div>
                <label className="mb-1 block text-xs font-medium">Description</label>
                <textarea
                  className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                  rows={2}
                  value={form.description}
                  onChange={(e) => setForm({ ...form, description: e.target.value })}
                  placeholder="What does this rule do?"
                />
              </div>

              <div className="rounded-md border p-3">
                <p className="mb-2 text-xs font-medium">Configuration (JSON values)</p>
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                  <div>
                    <label className="mb-0.5 block text-[10px] text-muted-foreground">
                      Time Window (hours)
                    </label>
                    <input
                      type="text"
                      className="w-full rounded border bg-background px-2 py-1 text-xs"
                      value={form.config.time_window_hours ?? ''}
                      onChange={(e) =>
                        setForm({
                          ...form,
                          config: { ...form.config, time_window_hours: e.target.value },
                        })
                      }
                    />
                  </div>
                  <div>
                    <label className="mb-0.5 block text-[10px] text-muted-foreground">
                      Name Threshold (0-100)
                    </label>
                    <input
                      type="text"
                      className="w-full rounded border bg-background px-2 py-1 text-xs"
                      value={form.config.name_threshold ?? ''}
                      onChange={(e) =>
                        setForm({
                          ...form,
                          config: { ...form.config, name_threshold: e.target.value },
                        })
                      }
                    />
                  </div>
                  <div>
                    <label className="mb-0.5 block text-[10px] text-muted-foreground">
                      Address Threshold (0-1)
                    </label>
                    <input
                      type="text"
                      className="w-full rounded border bg-background px-2 py-1 text-xs"
                      value={form.config.address_threshold ?? ''}
                      onChange={(e) =>
                        setForm({
                          ...form,
                          config: { ...form.config, address_threshold: e.target.value },
                        })
                      }
                    />
                  </div>
                  <div>
                    <label className="mb-0.5 block text-[10px] text-muted-foreground">Limit</label>
                    <input
                      type="text"
                      className="w-full rounded border bg-background px-2 py-1 text-xs"
                      value={form.config.limit ?? ''}
                      onChange={(e) =>
                        setForm({
                          ...form,
                          config: { ...form.config, limit: e.target.value },
                        })
                      }
                    />
                  </div>
                </div>
              </div>

              <label className="flex items-center gap-2 text-sm">
                <input
                  type="checkbox"
                  checked={form.is_enabled}
                  onChange={(e) => setForm({ ...form, is_enabled: e.target.checked })}
                />
                Enabled
              </label>

              <div className="flex gap-2">
                <Button size="sm" onClick={save} disabled={saving || !form.name}>
                  <Save className="mr-1.5 h-4 w-4" />
                  {saving ? 'Saving…' : 'Save Rule'}
                </Button>
                <Button size="sm" variant="outline" onClick={resetForm}>
                  <X className="mr-1.5 h-4 w-4" />
                  Cancel
                </Button>
              </div>
            </CardContent>
          </Card>
        )}

        {/* Rules List */}
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Configured Rules ({rules.length})</CardTitle>
          </CardHeader>
          <CardContent>
            {rules.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-12 text-center">
                <AlertTriangle className="h-12 w-12 text-muted-foreground/50" />
                <p className="mt-3 text-sm text-muted-foreground">
                  No detection rules configured. Click "Add Rule" to create one.
                </p>
              </div>
            ) : (
              <div className="space-y-3">
                {rules.map((rule) => (
                  <div
                    key={rule.id}
                    className="flex flex-col gap-3 rounded-lg border p-4 lg:flex-row lg:items-start lg:justify-between"
                  >
                    <div className="min-w-0 flex-1 space-y-1.5">
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="font-medium">{rule.name}</span>
                        <Badge className={typeColors[rule.type] ?? ''}>
                          {typeLabels[rule.type] ?? rule.type}
                        </Badge>
                        {rule.match_method && (
                          <span className="text-xs text-muted-foreground">
                            method: {rule.match_method}
                          </span>
                        )}
                        <span className="text-xs text-muted-foreground">
                          priority: {rule.priority}
                        </span>
                        <Badge
                          className={
                            rule.is_enabled
                              ? 'bg-green-100 text-green-700'
                              : 'bg-muted text-muted-foreground'
                          }
                        >
                          {rule.is_enabled ? 'Enabled' : 'Disabled'}
                        </Badge>
                      </div>
                      {rule.description && (
                        <p className="text-sm text-muted-foreground">{rule.description}</p>
                      )}
                      {rule.config && Object.keys(rule.config).length > 0 && (
                        <div className="flex flex-wrap gap-1.5">
                          {Object.entries(rule.config).map(([key, value]) => (
                            <span
                              key={key}
                              className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-mono"
                            >
                              {key}: {String(value)}
                            </span>
                          ))}
                        </div>
                      )}
                      <p className="text-xs text-muted-foreground">
                        Created by {rule.created_by ?? 'Unknown'} ·{' '}
                        {formatDateTime(rule.created_at)}
                        {rule.updated_by && ` · Updated by ${rule.updated_by}`}
                      </p>
                    </div>
                    <div className="flex shrink-0 gap-2">
                      <Button size="sm" variant="outline" onClick={() => toggle(rule.id)}>
                        <Power className="h-3.5 w-3.5" />
                      </Button>
                      <Button size="sm" variant="outline" onClick={() => startEdit(rule)}>
                        <Pencil className="h-3.5 w-3.5" />
                      </Button>
                      <Button size="sm" variant="outline" onClick={() => remove(rule.id)}>
                        <Trash2 className="h-3.5 w-3.5" />
                      </Button>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
