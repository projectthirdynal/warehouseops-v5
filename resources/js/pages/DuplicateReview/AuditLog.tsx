import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import {
  ArrowLeft,
  ScrollText,
  Download,
  GitMerge,
  X,
  Eye,
  Settings,
  Bell,
  Search,
} from 'lucide-react';
import { formatDateTime } from '@/lib/utils';

interface AuditLog {
  id: number;
  user_id: number | null;
  action: string;
  entity_type: string | null;
  entity_id: number | null;
  entity_label: string | null;
  before_state: Record<string, unknown> | null;
  after_state: Record<string, unknown> | null;
  note: string | null;
  ip_address: string | null;
  user_agent: string | null;
  created_at: string;
  user: { id: number; name: string } | null;
}

interface Stats {
  total: number;
  days: number;
  total_merges: number;
  total_dismissals: number;
  by_action: Record<string, number>;
  by_entity_type: Record<string, number>;
  top_users: { user_id: number; name: string; count: number }[];
  daily_trend: Record<string, number>;
}

interface Props {
  logs: {
    items: AuditLog[];
    meta: {
      current_page: number;
      last_page: number;
      per_page: number;
      total: number;
      from: number;
      to: number;
    };
  };
  stats: Stats;
  filters: { action?: string; entity_type?: string; from?: string; to?: string };
}

const actionIcon: Record<string, typeof ScrollText> = {
  merge: GitMerge,
  auto_merge_approve: GitMerge,
  auto_merge_reject: X,
  family_merge: GitMerge,
  family_dismiss: X,
  family_build: Search,
  review: Eye,
  scan: Search,
  rule_create: Settings,
  rule_update: Settings,
  rule_delete: Settings,
  rule_toggle: Settings,
  notification_generate: Bell,
};

const actionColor: Record<string, string> = {
  merge: 'bg-green-100 text-green-700',
  auto_merge_approve: 'bg-green-100 text-green-700',
  auto_merge_reject: 'bg-red-100 text-red-700',
  family_merge: 'bg-green-100 text-green-700',
  family_dismiss: 'bg-red-100 text-red-700',
  family_build: 'bg-blue-100 text-blue-700',
  review: 'bg-yellow-100 text-yellow-700',
  scan: 'bg-blue-100 text-blue-700',
  rule_create: 'bg-purple-100 text-purple-700',
  rule_update: 'bg-purple-100 text-purple-700',
  rule_delete: 'bg-red-100 text-red-700',
  rule_toggle: 'bg-purple-100 text-purple-700',
  notification_generate: 'bg-orange-100 text-orange-700',
};

export default function DuplicateReviewAuditLog({ logs, stats, filters }: Props) {
  const [actionFilter, setActionFilter] = useState(filters.action ?? '');
  const [entityTypeFilter, setEntityTypeFilter] = useState(filters.entity_type ?? '');
  const [fromDate, setFromDate] = useState(filters.from ?? '');
  const [toDate, setToDate] = useState(filters.to ?? '');

  const applyFilters = (
    newAction: string,
    newEntityType: string,
    newFrom: string,
    newTo: string
  ) => {
    router.get(
      '/shop/duplicate-review/audit-log',
      {
        action: newAction || undefined,
        entity_type: newEntityType || undefined,
        from: newFrom || undefined,
        to: newTo || undefined,
      },
      { preserveScroll: true }
    );
  };

  const changeAction = (newAction: string) => {
    setActionFilter(newAction);
    applyFilters(newAction, entityTypeFilter, fromDate, toDate);
  };

  const changeEntityType = (newEntityType: string) => {
    setEntityTypeFilter(newEntityType);
    applyFilters(actionFilter, newEntityType, fromDate, toDate);
  };

  const applyDateRange = () => {
    applyFilters(actionFilter, entityTypeFilter, fromDate, toDate);
  };

  const exportCsv = () => {
    const params = new URLSearchParams();
    if (actionFilter) params.set('action', actionFilter);
    if (entityTypeFilter) params.set('entity_type', entityTypeFilter);
    if (fromDate) params.set('from', fromDate);
    if (toDate) params.set('to', toDate);
    window.location.href = `/api/duplicate-check/audit-log/export?${params.toString()}`;
  };

  const actions = [
    'merge',
    'review',
    'scan',
    'auto_merge_approve',
    'auto_merge_reject',
    'family_build',
    'family_merge',
    'family_dismiss',
    'rule_create',
    'rule_update',
    'rule_delete',
    'rule_toggle',
    'notification_generate',
  ];

  const entityTypes = [
    'customer',
    'order',
    'conversation',
    'review_item',
    'auto_merge_suggestion',
    'family',
    'notification',
    'rule',
  ];

  return (
    <AppLayout>
      <div className="space-y-6 p-6">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div>
            <h1 className="flex items-center gap-2 text-2xl font-bold">
              <ScrollText className="h-7 w-7 text-info" />
              Audit Log
            </h1>
            <p className="mt-1 text-sm text-muted-foreground">
              Complete trail of all duplicate-related actions: merges, dismissals, rule changes,
              scans, and notifications.
            </p>
          </div>
          <div className="flex gap-2">
            <Link href="/shop/duplicate-review">
              <Button variant="outline" size="sm">
                <ArrowLeft className="mr-1.5 h-4 w-4" />
                Back to Queue
              </Button>
            </Link>
            <Button onClick={exportCsv} size="sm" variant="outline">
              <Download className="mr-1.5 h-4 w-4" />
              Export CSV
            </Button>
          </div>
        </div>

        {/* Stats Cards */}
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <p className="text-xs text-muted-foreground">Total Actions ({stats.days}d)</p>
                <ScrollText className="h-4 w-4 text-muted-foreground" />
              </div>
              <p className="text-2xl font-bold">{stats.total}</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <p className="text-xs text-muted-foreground">Merges</p>
                <GitMerge className="h-4 w-4 text-green-500" />
              </div>
              <p className="text-2xl font-bold text-green-600">{stats.total_merges}</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <p className="text-xs text-muted-foreground">Dismissals</p>
                <X className="h-4 w-4 text-red-500" />
              </div>
              <p className="text-2xl font-bold text-red-600">{stats.total_dismissals}</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <p className="text-xs text-muted-foreground">Top Actor</p>
                <Settings className="h-4 w-4 text-muted-foreground" />
              </div>
              <p className="truncate text-lg font-bold">{stats.top_users[0]?.name ?? '—'}</p>
              <p className="text-xs text-muted-foreground">
                {stats.top_users[0]?.count ?? 0} actions
              </p>
            </CardContent>
          </Card>
        </div>

        {/* By Action Distribution */}
        {Object.keys(stats.by_action).length > 0 && (
          <Card>
            <CardContent className="p-4">
              <p className="mb-3 text-sm font-medium">Actions by Type</p>
              <div className="flex flex-wrap gap-2">
                {Object.entries(stats.by_action)
                  .sort((a, b) => b[1] - a[1])
                  .map(([action, count]) => (
                    <Badge key={action} className={actionColor[action] ?? 'bg-muted'}>
                      {action.replace(/_/g, ' ')}: {count}
                    </Badge>
                  ))}
              </div>
            </CardContent>
          </Card>
        )}

        {/* Filters */}
        <div className="flex flex-wrap items-center gap-4">
          <div className="flex items-center gap-2">
            <span className="text-sm text-muted-foreground">Action:</span>
            <select
              value={actionFilter}
              onChange={(e) => changeAction(e.target.value)}
              className="rounded-md border bg-background px-3 py-1 text-sm"
            >
              <option value="">All</option>
              {actions.map((a) => (
                <option key={a} value={a}>
                  {a.replace(/_/g, ' ')}
                </option>
              ))}
            </select>
          </div>
          <div className="flex items-center gap-2">
            <span className="text-sm text-muted-foreground">Entity:</span>
            <select
              value={entityTypeFilter}
              onChange={(e) => changeEntityType(e.target.value)}
              className="rounded-md border bg-background px-3 py-1 text-sm"
            >
              <option value="">All</option>
              {entityTypes.map((t) => (
                <option key={t} value={t}>
                  {t}
                </option>
              ))}
            </select>
          </div>
          <div className="flex items-center gap-2">
            <span className="text-sm text-muted-foreground">From:</span>
            <input
              type="date"
              value={fromDate}
              onChange={(e) => setFromDate(e.target.value)}
              className="rounded-md border bg-background px-3 py-1 text-sm"
            />
          </div>
          <div className="flex items-center gap-2">
            <span className="text-sm text-muted-foreground">To:</span>
            <input
              type="date"
              value={toDate}
              onChange={(e) => setToDate(e.target.value)}
              className="rounded-md border bg-background px-3 py-1 text-sm"
            />
          </div>
          <Button size="sm" variant="outline" onClick={applyDateRange}>
            Apply
          </Button>
        </div>

        {/* Top Users */}
        {stats.top_users.length > 0 && (
          <Card>
            <CardContent className="p-4">
              <p className="mb-3 text-sm font-medium">Top Actors</p>
              <div className="space-y-2">
                {stats.top_users.map((user, idx) => (
                  <div key={user.user_id} className="flex items-center justify-between text-sm">
                    <span>
                      <span className="mr-2 font-bold text-muted-foreground">#{idx + 1}</span>
                      {user.name}
                    </span>
                    <Badge variant="outline">{user.count} actions</Badge>
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>
        )}

        {/* Audit Log Table */}
        {logs.items.length > 0 ? (
          <div className="space-y-3">
            {logs.items.map((log) => {
              const Icon = actionIcon[log.action] ?? ScrollText;
              return (
                <Card key={log.id}>
                  <CardContent className="p-4">
                    <div className="flex items-start gap-3">
                      <div
                        className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full ${
                          actionColor[log.action] ?? 'bg-muted'
                        }`}
                      >
                        <Icon className="h-4 w-4" />
                      </div>
                      <div className="min-w-0 flex-1">
                        <div className="flex items-center gap-2">
                          <Badge className={actionColor[log.action] ?? 'bg-muted'}>
                            {log.action.replace(/_/g, ' ')}
                          </Badge>
                          {log.entity_type && (
                            <Badge variant="outline" className="capitalize">
                              {log.entity_type.replace(/_/g, ' ')}
                            </Badge>
                          )}
                          {log.entity_label && (
                            <span className="truncate text-sm font-medium">{log.entity_label}</span>
                          )}
                        </div>
                        <div className="mt-1 flex items-center gap-3 text-xs text-muted-foreground">
                          <span>{log.user?.name ?? 'System'}</span>
                          <span>{formatDateTime(log.created_at)}</span>
                          {log.ip_address && <span>IP: {log.ip_address}</span>}
                        </div>
                        {log.note && (
                          <p className="mt-1 text-sm text-muted-foreground">{log.note}</p>
                        )}
                        {log.after_state && Object.keys(log.after_state).length > 0 && (
                          <details className="mt-2">
                            <summary className="cursor-pointer text-xs text-muted-foreground hover:text-foreground">
                              View state changes
                            </summary>
                            <div className="mt-1 grid grid-cols-2 gap-2 text-xs">
                              {log.before_state && Object.keys(log.before_state).length > 0 && (
                                <div className="rounded bg-red-50/30 p-2">
                                  <p className="mb-1 font-medium text-red-600">Before</p>
                                  <pre className="overflow-x-auto text-xs">
                                    {JSON.stringify(log.before_state, null, 2)}
                                  </pre>
                                </div>
                              )}
                              <div className="rounded bg-green-50/30 p-2">
                                <p className="mb-1 font-medium text-green-600">After</p>
                                <pre className="overflow-x-auto text-xs">
                                  {JSON.stringify(log.after_state, null, 2)}
                                </pre>
                              </div>
                            </div>
                          </details>
                        )}
                      </div>
                    </div>
                  </CardContent>
                </Card>
              );
            })}

            {/* Pagination */}
            {logs.meta.last_page > 1 && (
              <div className="flex items-center justify-center gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  disabled={logs.meta.current_page <= 1}
                  onClick={() =>
                    router.get(
                      '/shop/duplicate-review/audit-log',
                      {
                        action: actionFilter || undefined,
                        entity_type: entityTypeFilter || undefined,
                        from: fromDate || undefined,
                        to: toDate || undefined,
                        page: logs.meta.current_page - 1,
                      },
                      { preserveScroll: true }
                    )
                  }
                >
                  Previous
                </Button>
                <span className="text-sm text-muted-foreground">
                  Page {logs.meta.current_page} of {logs.meta.last_page} ({logs.meta.total} total)
                </span>
                <Button
                  variant="outline"
                  size="sm"
                  disabled={logs.meta.current_page >= logs.meta.last_page}
                  onClick={() =>
                    router.get(
                      '/shop/duplicate-review/audit-log',
                      {
                        action: actionFilter || undefined,
                        entity_type: entityTypeFilter || undefined,
                        from: fromDate || undefined,
                        to: toDate || undefined,
                        page: logs.meta.current_page + 1,
                      },
                      { preserveScroll: true }
                    )
                  }
                >
                  Next
                </Button>
              </div>
            )}
          </div>
        ) : (
          <Card>
            <CardContent className="flex flex-col items-center justify-center py-12">
              <ScrollText className="mb-3 h-12 w-12 text-muted-foreground" />
              <p className="text-sm text-muted-foreground">
                No audit log entries found. Actions on duplicate records will be logged here.
              </p>
            </CardContent>
          </Card>
        )}
      </div>
    </AppLayout>
  );
}
