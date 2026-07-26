import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
  AlertTriangle,
  CheckCircle,
  XCircle,
  Eye,
  RefreshCw,
  Users,
  ShoppingCart,
  MessageSquare,
  ArrowLeft,
} from 'lucide-react';
import { formatDateTime } from '@/lib/utils';

interface ReviewItem {
  id: number;
  type: string;
  primary_ref_id: number | null;
  duplicate_ref_id: number | null;
  primary_label: string | null;
  duplicate_label: string | null;
  match_method: string | null;
  similarity_score: number | null;
  severity: string;
  status: string;
  metadata: Record<string, unknown> | null;
  reviewed_by: { id: number; name: string } | null;
  reviewed_at: string | null;
  review_note: string | null;
  created_at: string;
}

interface QueueStats {
  total: number;
  pending: number;
  reviewed: number;
  dismissed: number;
  actioned: number;
  by_type: Record<string, Record<string, number>>;
  by_severity: Record<string, number>;
}

interface Props {
  queue: ReviewItem[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
  };
  stats: QueueStats;
  filters: {
    type?: string;
    status?: string;
    severity?: string;
    per_page?: string;
  };
}

const typeIcon: Record<string, typeof Users> = {
  order: ShoppingCart,
  customer: Users,
  conversation: MessageSquare,
};

const severityClass: Record<string, string> = {
  high: 'bg-destructive/10 text-destructive',
  medium: 'bg-warning/10 text-warning',
  low: 'bg-info/10 text-info',
};

const statusClass: Record<string, string> = {
  pending: 'bg-secondary text-secondary-foreground',
  reviewed: 'bg-info/10 text-info',
  dismissed: 'bg-muted text-muted-foreground',
  actioned: 'bg-primary/10 text-primary',
};

function getLink(type: string, refId: number | null): string {
  if (!refId) return '#';
  if (type === 'order') return `/orders/${refId}`;
  if (type === 'customer') return `/shop/customers/${refId}`;
  if (type === 'conversation') return `/shop/inbox?conversation=${refId}`;
  return '#';
}

function getMetadataDisplay(item: ReviewItem): string {
  const meta = item.metadata;
  if (!meta) return '';
  const parts: string[] = [];
  if (meta.phone) parts.push(`Phone: ${meta.phone}`);
  if (meta.normalized_phone) parts.push(`Phone: ${meta.normalized_phone}`);
  if (meta.psid) parts.push(`PSID: ${meta.psid}`);
  if (meta.display_name) parts.push(`Name: ${meta.display_name}`);
  if (meta.primary_total_orders !== undefined)
    parts.push(`Primary orders: ${meta.primary_total_orders}`);
  if (meta.duplicate_total_orders !== undefined)
    parts.push(`Duplicate orders: ${meta.duplicate_total_orders}`);
  return parts.join(' · ');
}

export default function DuplicateReviewIndex({ queue, meta, stats, filters }: Props) {
  const [scanning, setScanning] = useState(false);
  const [resolvingId, setResolvingId] = useState<number | null>(null);
  const [resolveNote, setResolveNote] = useState<Record<number, string>>({});

  const triggerScan = async () => {
    setScanning(true);
    try {
      const csrfMeta = document.querySelector('meta[name="csrf-token"]');
      const res = await fetch('/api/duplicate-check/scan', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-TOKEN': csrfMeta?.getAttribute('content') ?? '',
        },
      });
      const data = await res.json();
      router.reload({ only: ['queue', 'stats'] });
      alert(`Scan complete: ${data.created} new items, ${data.skipped} already existed.`);
    } catch {
      alert('Scan failed. Please try again.');
    } finally {
      setScanning(false);
    }
  };

  const resolveItem = async (id: number, status: 'reviewed' | 'dismissed' | 'actioned') => {
    setResolvingId(id);
    try {
      const csrfMeta = document.querySelector('meta[name="csrf-token"]');
      await fetch(`/api/duplicate-check/review-queue/${id}/resolve`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-TOKEN': csrfMeta?.getAttribute('content') ?? '',
        },
        body: JSON.stringify({ status, note: resolveNote[id] ?? null }),
      });
      router.reload({ only: ['queue', 'stats'] });
    } catch {
      alert('Failed to resolve item.');
    } finally {
      setResolvingId(null);
    }
  };

  const updateFilter = (key: string, value: string) => {
    const params = new URLSearchParams();
    if (filters.type && key !== 'type') params.set('type', filters.type);
    if (filters.status && key !== 'status') params.set('status', filters.status);
    if (filters.severity && key !== 'severity') params.set('severity', filters.severity);
    if (value) params.set(key, value);
    const qs = params.toString();
    router.get(`/shop/duplicate-review${qs ? `?${qs}` : ''}`, {}, { preserveScroll: true });
  };

  return (
    <AppLayout>
      <div className="space-y-6 p-6">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="flex items-center gap-2 text-2xl font-bold">
              <AlertTriangle className="h-7 w-7 text-warning" />
              Duplicate Review Queue
            </h1>
            <p className="mt-1 text-sm text-muted-foreground">
              Review and resolve detected duplicate orders, customers, and conversations.
            </p>
          </div>
          <div className="flex gap-2">
            <Link href="/shop">
              <Button variant="outline" size="sm">
                <ArrowLeft className="mr-1.5 h-4 w-4" />
                Back to Shop
              </Button>
            </Link>
            <Button onClick={triggerScan} disabled={scanning} size="sm">
              <RefreshCw className={`mr-1.5 h-4 w-4 ${scanning ? 'animate-spin' : ''}`} />
              {scanning ? 'Scanning…' : 'Run Scan'}
            </Button>
          </div>
        </div>

        {/* Stats Cards */}
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
          <Card>
            <CardContent className="p-4">
              <p className="text-xs text-muted-foreground">Total</p>
              <p className="text-2xl font-bold">{stats.total}</p>
            </CardContent>
          </Card>
          <Card className={stats.pending > 0 ? 'border-warning/30' : ''}>
            <CardContent className="p-4">
              <p className="text-xs text-muted-foreground">Pending</p>
              <p className="text-2xl font-bold text-warning">{stats.pending}</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <p className="text-xs text-muted-foreground">Reviewed</p>
              <p className="text-2xl font-bold text-info">{stats.reviewed}</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <p className="text-xs text-muted-foreground">Dismissed</p>
              <p className="text-2xl font-bold text-muted-foreground">{stats.dismissed}</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <p className="text-xs text-muted-foreground">Actioned</p>
              <p className="text-2xl font-bold text-primary">{stats.actioned}</p>
            </CardContent>
          </Card>
        </div>

        {/* Filters */}
        <div className="flex flex-wrap items-center gap-3">
          <select
            className="rounded-md border bg-background px-3 py-1.5 text-sm"
            value={filters.type ?? ''}
            onChange={(e) => updateFilter('type', e.target.value)}
          >
            <option value="">All Types</option>
            <option value="order">Orders</option>
            <option value="customer">Customers</option>
            <option value="conversation">Conversations</option>
          </select>
          <select
            className="rounded-md border bg-background px-3 py-1.5 text-sm"
            value={filters.status ?? ''}
            onChange={(e) => updateFilter('status', e.target.value)}
          >
            <option value="">All Statuses</option>
            <option value="pending">Pending</option>
            <option value="reviewed">Reviewed</option>
            <option value="dismissed">Dismissed</option>
            <option value="actioned">Actioned</option>
          </select>
          <select
            className="rounded-md border bg-background px-3 py-1.5 text-sm"
            value={filters.severity ?? ''}
            onChange={(e) => updateFilter('severity', e.target.value)}
          >
            <option value="">All Severities</option>
            <option value="high">High</option>
            <option value="medium">Medium</option>
            <option value="low">Low</option>
          </select>
        </div>

        {/* Queue Table */}
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Review Items ({meta.total})</CardTitle>
          </CardHeader>
          <CardContent>
            {queue.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-12 text-center">
                <CheckCircle className="h-12 w-12 text-muted-foreground/50" />
                <p className="mt-3 text-sm text-muted-foreground">
                  No duplicate items to review. Run a scan to check for new duplicates.
                </p>
              </div>
            ) : (
              <div className="space-y-3">
                {queue.map((item) => {
                  const Icon = typeIcon[item.type] ?? AlertTriangle;
                  const meta = getMetadataDisplay(item);
                  return (
                    <div
                      key={item.id}
                      className="flex flex-col gap-3 rounded-lg border p-4 lg:flex-row lg:items-start lg:justify-between"
                    >
                      <div className="min-w-0 flex-1 space-y-2">
                        <div className="flex flex-wrap items-center gap-2">
                          <Icon className="h-4 w-4 text-muted-foreground" />
                          <Badge className={severityClass[item.severity] ?? ''}>
                            {item.severity}
                          </Badge>
                          <Badge className={statusClass[item.status] ?? ''}>{item.status}</Badge>
                          <span className="text-xs text-muted-foreground">{item.match_method}</span>
                          {item.similarity_score !== null && (
                            <span className="text-xs text-muted-foreground">
                              {item.similarity_score}% match
                            </span>
                          )}
                        </div>
                        <div className="flex flex-wrap items-center gap-3 text-sm">
                          <div>
                            <span className="text-xs text-muted-foreground">Primary: </span>
                            <Link
                              href={getLink(item.type, item.primary_ref_id)}
                              className="font-medium text-info hover:underline"
                            >
                              {item.primary_label}
                            </Link>
                          </div>
                          <div>
                            <span className="text-xs text-muted-foreground">Duplicate: </span>
                            <Link
                              href={getLink(item.type, item.duplicate_ref_id)}
                              className="font-medium text-info hover:underline"
                            >
                              {item.duplicate_label}
                            </Link>
                          </div>
                        </div>
                        {meta && <p className="text-xs text-muted-foreground">{meta}</p>}
                        {item.review_note && (
                          <p className="text-xs italic text-muted-foreground">
                            Note: {item.review_note}
                          </p>
                        )}
                        {item.reviewed_by && (
                          <p className="text-xs text-muted-foreground">
                            Reviewed by {item.reviewed_by.name}{' '}
                            {item.reviewed_at && `on ${formatDateTime(item.reviewed_at)}`}
                          </p>
                        )}
                        {item.status === 'pending' && (
                          <input
                            type="text"
                            placeholder="Add a note (optional)…"
                            className="mt-1 w-full max-w-md rounded-md border bg-background px-2 py-1 text-xs"
                            value={resolveNote[item.id] ?? ''}
                            onChange={(e) =>
                              setResolveNote((prev) => ({
                                ...prev,
                                [item.id]: e.target.value,
                              }))
                            }
                          />
                        )}
                      </div>
                      {item.status === 'pending' && (
                        <div className="flex shrink-0 gap-2">
                          <Button
                            size="sm"
                            variant="outline"
                            disabled={resolvingId === item.id}
                            onClick={() => resolveItem(item.id, 'reviewed')}
                          >
                            <Eye className="mr-1 h-3.5 w-3.5" />
                            Reviewed
                          </Button>
                          <Button
                            size="sm"
                            variant="outline"
                            disabled={resolvingId === item.id}
                            onClick={() => resolveItem(item.id, 'dismissed')}
                          >
                            <XCircle className="mr-1 h-3.5 w-3.5" />
                            Dismiss
                          </Button>
                          <Button
                            size="sm"
                            disabled={resolvingId === item.id}
                            onClick={() => resolveItem(item.id, 'actioned')}
                          >
                            <CheckCircle className="mr-1 h-3.5 w-3.5" />
                            Actioned
                          </Button>
                        </div>
                      )}
                    </div>
                  );
                })}
              </div>
            )}

            {/* Pagination */}
            {meta.last_page > 1 && (
              <div className="mt-4 flex items-center justify-between">
                <p className="text-xs text-muted-foreground">
                  Showing {meta.from ?? 0}–{meta.to ?? 0} of {meta.total}
                </p>
                <div className="flex gap-2">
                  {meta.current_page > 1 && (
                    <Button
                      size="sm"
                      variant="outline"
                      onClick={() => {
                        const params = new URLSearchParams(filters as Record<string, string>);
                        params.set('page', String(meta.current_page - 1));
                        router.get(`/shop/duplicate-review?${params.toString()}`);
                      }}
                    >
                      Previous
                    </Button>
                  )}
                  <span className="px-2 py-1 text-sm">
                    Page {meta.current_page} of {meta.last_page}
                  </span>
                  {meta.current_page < meta.last_page && (
                    <Button
                      size="sm"
                      variant="outline"
                      onClick={() => {
                        const params = new URLSearchParams(filters as Record<string, string>);
                        params.set('page', String(meta.current_page + 1));
                        router.get(`/shop/duplicate-review?${params.toString()}`);
                      }}
                    >
                      Next
                    </Button>
                  )}
                </div>
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
