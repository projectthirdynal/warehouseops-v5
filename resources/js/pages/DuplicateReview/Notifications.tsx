import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import {
  ArrowLeft,
  Bell,
  CheckCheck,
  RefreshCw,
  AlertTriangle,
  GitMerge,
  Users,
} from 'lucide-react';
import { formatDateTime } from '@/lib/utils';

interface Notification {
  id: number;
  user_id: number | null;
  type: string;
  severity: string;
  title: string;
  message: string;
  entity_type: string | null;
  entity_id: number | null;
  action_url: string | null;
  action_label: string | null;
  metadata: Record<string, unknown> | null;
  read_at: string | null;
  read_by: number | null;
  created_at: string;
  reader: { id: number; name: string } | null;
}

interface Stats {
  total: number;
  unread: number;
  recent_7d: number;
  by_type: Record<string, number>;
  by_severity: Record<string, number>;
  unread_by_type: Record<string, number>;
  unread_by_severity: Record<string, number>;
}

interface Props {
  notifications: {
    items: Notification[];
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
  filters: { type?: string; severity?: string; unread_only?: string };
}

const severityColor: Record<string, string> = {
  low: 'bg-blue-100 text-blue-700',
  medium: 'bg-yellow-100 text-yellow-700',
  high: 'bg-orange-100 text-orange-700',
  critical: 'bg-red-100 text-red-700',
};

const typeIcon: Record<string, typeof Bell> = {
  review_item: AlertTriangle,
  auto_merge: GitMerge,
  family: Users,
  high_severity: AlertTriangle,
};

const typeColor: Record<string, string> = {
  review_item: 'bg-orange-100 text-orange-700',
  auto_merge: 'bg-purple-100 text-purple-700',
  family: 'bg-blue-100 text-blue-700',
  high_severity: 'bg-red-100 text-red-700',
};

export default function DuplicateReviewNotifications({ notifications, stats, filters }: Props) {
  const [generating, setGenerating] = useState(false);
  const [markingAll, setMarkingAll] = useState(false);
  const [typeFilter, setTypeFilter] = useState(filters.type ?? '');
  const [severityFilter, setSeverityFilter] = useState(filters.severity ?? '');
  const [unreadOnly, setUnreadOnly] = useState(filters.unread_only === '1');
  const [generateResult, setGenerateResult] = useState<string | null>(null);

  const csrfToken =
    (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '';

  const generate = async () => {
    setGenerating(true);
    setGenerateResult(null);
    try {
      const res = await fetch('/api/duplicate-check/notifications/generate', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
      });
      const data = await res.json();
      setGenerateResult(`Created ${data.created} notifications, skipped ${data.skipped} existing.`);
      router.reload();
    } catch {
      setGenerateResult('Generation failed. Please try again.');
    } finally {
      setGenerating(false);
    }
  };

  const markRead = async (id: number) => {
    await fetch(`/api/duplicate-check/notifications/${id}/read`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
    });
    router.reload();
  };

  const markAllRead = async () => {
    setMarkingAll(true);
    try {
      await fetch('/api/duplicate-check/notifications/mark-all-read', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
      });
      router.reload();
    } finally {
      setMarkingAll(false);
    }
  };

  const applyFilters = (newType: string, newSeverity: string, newUnread: boolean) => {
    router.get(
      '/shop/duplicate-review/notifications',
      {
        type: newType || undefined,
        severity: newSeverity || undefined,
        unread_only: newUnread ? '1' : undefined,
      },
      { preserveScroll: true }
    );
  };

  const changeTypeFilter = (newType: string) => {
    setTypeFilter(newType);
    applyFilters(newType, severityFilter, unreadOnly);
  };

  const changeSeverityFilter = (newSeverity: string) => {
    setSeverityFilter(newSeverity);
    applyFilters(typeFilter, newSeverity, unreadOnly);
  };

  const toggleUnreadOnly = () => {
    const newVal = !unreadOnly;
    setUnreadOnly(newVal);
    applyFilters(typeFilter, severityFilter, newVal);
  };

  return (
    <AppLayout>
      <div className="space-y-6 p-6">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div>
            <h1 className="flex items-center gap-2 text-2xl font-bold">
              <Bell className="h-7 w-7 text-info" />
              Duplicate Notifications
            </h1>
            <p className="mt-1 text-sm text-muted-foreground">
              Supervisor alerts for high-severity duplicates, auto-merge suggestions, and large
              families.
            </p>
          </div>
          <div className="flex gap-2">
            <Link href="/shop/duplicate-review">
              <Button variant="outline" size="sm">
                <ArrowLeft className="mr-1.5 h-4 w-4" />
                Back to Queue
              </Button>
            </Link>
            <Link href="/shop/duplicate-review/auto-merge">
              <Button variant="outline" size="sm">
                Auto-Merge
              </Button>
            </Link>
            <Link href="/shop/duplicate-review/families">
              <Button variant="outline" size="sm">
                Families
              </Button>
            </Link>
          </div>
        </div>

        {/* Stats Cards */}
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <p className="text-xs text-muted-foreground">Unread</p>
                <Bell className="h-4 w-4 text-warning" />
              </div>
              <p className="text-2xl font-bold text-warning">{stats.unread}</p>
              <p className="text-xs text-muted-foreground">awaiting attention</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <p className="text-xs text-muted-foreground">Total</p>
                <Bell className="h-4 w-4 text-muted-foreground" />
              </div>
              <p className="text-2xl font-bold">{stats.total}</p>
              <p className="text-xs text-muted-foreground">all-time notifications</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <p className="text-xs text-muted-foreground">Last 7 Days</p>
                <RefreshCw className="h-4 w-4 text-info" />
              </div>
              <p className="text-2xl font-bold">{stats.recent_7d}</p>
              <p className="text-xs text-muted-foreground">new notifications</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <p className="text-xs text-muted-foreground">Critical Unread</p>
                <AlertTriangle className="h-4 w-4 text-red-500" />
              </div>
              <p className="text-2xl font-bold text-red-600">
                {stats.unread_by_severity?.critical ?? 0}
              </p>
              <p className="text-xs text-muted-foreground">
                high: {stats.unread_by_severity?.high ?? 0}
              </p>
            </CardContent>
          </Card>
        </div>

        {/* Action Bar */}
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-4">
            <div className="flex items-center gap-2">
              <span className="text-sm text-muted-foreground">Type:</span>
              {['', 'review_item', 'auto_merge', 'family'].map((t) => (
                <button
                  key={t || 'all'}
                  onClick={() => changeTypeFilter(t)}
                  className={`rounded-md px-3 py-1 text-sm capitalize ${
                    typeFilter === t
                      ? 'bg-primary text-primary-foreground'
                      : 'bg-muted text-muted-foreground hover:bg-muted/80'
                  }`}
                >
                  {t === 'review_item' ? 'Review Items' : t || 'All'}
                </button>
              ))}
            </div>
            <div className="flex items-center gap-2">
              <span className="text-sm text-muted-foreground">Severity:</span>
              {['', 'low', 'medium', 'high', 'critical'].map((s) => (
                <button
                  key={s || 'all'}
                  onClick={() => changeSeverityFilter(s)}
                  className={`rounded-md px-3 py-1 text-sm capitalize ${
                    severityFilter === s
                      ? 'bg-primary text-primary-foreground'
                      : 'bg-muted text-muted-foreground hover:bg-muted/80'
                  }`}
                >
                  {s || 'All'}
                </button>
              ))}
            </div>
            <button
              onClick={toggleUnreadOnly}
              className={`rounded-md px-3 py-1 text-sm ${
                unreadOnly
                  ? 'bg-primary text-primary-foreground'
                  : 'bg-muted text-muted-foreground hover:bg-muted/80'
              }`}
            >
              Unread Only
            </button>
          </div>
          <div className="flex gap-2">
            <Button onClick={generate} disabled={generating} size="sm" variant="outline">
              <RefreshCw className={`mr-1.5 h-4 w-4 ${generating ? 'animate-spin' : ''}`} />
              {generating ? 'Generating...' : 'Generate'}
            </Button>
            {stats.unread > 0 && (
              <Button onClick={markAllRead} disabled={markingAll} size="sm">
                <CheckCheck className="mr-1.5 h-4 w-4" />
                {markingAll ? 'Marking...' : 'Mark All Read'}
              </Button>
            )}
          </div>
        </div>

        {generateResult && (
          <div className="rounded-md border border-info/20 bg-info/5 p-3 text-sm text-info">
            {generateResult}
          </div>
        )}

        {/* Notifications List */}
        {notifications.items.length > 0 ? (
          <div className="space-y-3">
            {notifications.items.map((notification) => {
              const Icon = typeIcon[notification.type] ?? Bell;
              const isUnread = notification.read_at === null;
              return (
                <Card
                  key={notification.id}
                  className={isUnread ? 'border-l-4 border-l-warning' : ''}
                >
                  <CardContent className="p-4">
                    <div className="flex items-start gap-3">
                      {/* Icon */}
                      <div
                        className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-full ${
                          typeColor[notification.type] ?? 'bg-muted'
                        }`}
                      >
                        <Icon className="h-5 w-5" />
                      </div>

                      {/* Content */}
                      <div className="min-w-0 flex-1">
                        <div className="flex items-center gap-2">
                          <p className={`text-sm ${isUnread ? 'font-bold' : 'font-medium'}`}>
                            {notification.title}
                          </p>
                          <Badge className={severityColor[notification.severity] ?? ''}>
                            {notification.severity}
                          </Badge>
                          <Badge variant="outline" className="capitalize">
                            {notification.type.replace('_', ' ')}
                          </Badge>
                          {isUnread && <span className="h-2 w-2 rounded-full bg-warning" />}
                        </div>
                        <p className="mt-1 text-sm text-muted-foreground">{notification.message}</p>

                        {/* Footer */}
                        <div className="mt-2 flex items-center gap-3 text-xs text-muted-foreground">
                          <span>{formatDateTime(notification.created_at)}</span>
                          {notification.read_at && (
                            <span>
                              Read by {notification.reader?.name ?? 'Unknown'} on{' '}
                              {formatDateTime(notification.read_at)}
                            </span>
                          )}
                          {notification.action_url && notification.action_label && (
                            <Link
                              href={notification.action_url}
                              className="font-medium text-info hover:underline"
                            >
                              {notification.action_label} →
                            </Link>
                          )}
                        </div>
                      </div>

                      {/* Action */}
                      {isUnread && (
                        <Button size="sm" variant="ghost" onClick={() => markRead(notification.id)}>
                          <CheckCheck className="h-4 w-4" />
                        </Button>
                      )}
                    </div>
                  </CardContent>
                </Card>
              );
            })}

            {/* Pagination */}
            {notifications.meta.last_page > 1 && (
              <div className="flex items-center justify-center gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  disabled={notifications.meta.current_page <= 1}
                  onClick={() =>
                    router.get(
                      '/shop/duplicate-review/notifications',
                      {
                        type: typeFilter || undefined,
                        severity: severityFilter || undefined,
                        unread_only: unreadOnly ? '1' : undefined,
                        page: notifications.meta.current_page - 1,
                      },
                      { preserveScroll: true }
                    )
                  }
                >
                  Previous
                </Button>
                <span className="text-sm text-muted-foreground">
                  Page {notifications.meta.current_page} of {notifications.meta.last_page} (
                  {notifications.meta.total} total)
                </span>
                <Button
                  variant="outline"
                  size="sm"
                  disabled={notifications.meta.current_page >= notifications.meta.last_page}
                  onClick={() =>
                    router.get(
                      '/shop/duplicate-review/notifications',
                      {
                        type: typeFilter || undefined,
                        severity: severityFilter || undefined,
                        unread_only: unreadOnly ? '1' : undefined,
                        page: notifications.meta.current_page + 1,
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
              <Bell className="mb-3 h-12 w-12 text-muted-foreground" />
              <p className="text-sm text-muted-foreground">
                No notifications found. Generate notifications from current duplicate state.
              </p>
              <Button onClick={generate} disabled={generating} size="sm" className="mt-4">
                <RefreshCw className={`mr-1.5 h-4 w-4 ${generating ? 'animate-spin' : ''}`} />
                {generating ? 'Generating...' : 'Generate Notifications'}
              </Button>
            </CardContent>
          </Card>
        )}
      </div>
    </AppLayout>
  );
}
