import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import {
  AlertTriangle,
  ArrowLeft,
  Check,
  X,
  RefreshCw,
  GitMerge,
  Users,
  TrendingUp,
  Shield,
} from 'lucide-react';
import { formatDateTime } from '@/lib/utils';

interface CustomerSummary {
  id: number;
  name: string;
  phone: string;
  normalized_phone: string;
  facebook_name: string;
  total_orders: number;
  total_revenue: number;
  risk_level: string;
  is_blacklisted: boolean;
  created_at: string;
}

interface MergePreview {
  can_merge: boolean;
  target: {
    id: number;
    name: string;
    phone: string;
    total_orders: number;
    total_revenue: number;
    risk_level: string;
    is_blacklisted: boolean;
  };
  source: {
    id: number;
    name: string;
    phone: string;
    total_orders: number;
    total_revenue: number;
    risk_level: string;
    is_blacklisted: boolean;
  };
  transfer_summary: {
    orders: number;
    conversations: number;
    identities: number;
    addresses: number;
    notes: number;
    leads: number;
    total_records: number;
  };
  merged_stats: {
    total_orders: number;
    successful_orders: number;
    returned_orders: number;
    total_revenue: number;
  };
  filled_fields: string[];
  risk_will_change: boolean;
  new_risk_level: string;
}

interface Suggestion {
  id: number;
  target_customer_id: number;
  source_customer_id: number;
  confidence_score: number;
  match_reasons: string[];
  merge_preview: MergePreview;
  status: string;
  actioned_by: number | null;
  actioned_at: string | null;
  action_note: string | null;
  created_at: string;
  target_customer: CustomerSummary;
  source_customer: CustomerSummary;
  actioner: { id: number; name: string } | null;
}

interface Stats {
  total: number;
  pending: number;
  merged: number;
  rejected: number;
  by_confidence: { high: number; medium: number; low: number };
  avg_confidence: number;
}

interface Props {
  suggestions: {
    items: Suggestion[];
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
  filters: { status?: string; min_confidence?: string };
}

const confidenceColor = (score: number): string => {
  if (score >= 90) return 'bg-green-100 text-green-700';
  if (score >= 75) return 'bg-yellow-100 text-yellow-700';
  return 'bg-orange-100 text-orange-700';
};

const matchReasonColor: Record<string, string> = {
  phone: 'bg-blue-100 text-blue-700',
  psid: 'bg-purple-100 text-purple-700',
  name: 'bg-teal-100 text-teal-700',
  address: 'bg-gray-100 text-gray-700',
};

const statusColor: Record<string, string> = {
  pending: 'bg-secondary text-secondary-foreground',
  merged: 'bg-green-100 text-green-700',
  rejected: 'bg-red-100 text-red-700',
};

export default function DuplicateReviewAutoMerge({ suggestions, stats, filters }: Props) {
  const [scanning, setScanning] = useState(false);
  const [actioningId, setActioningId] = useState<number | null>(null);
  const [statusFilter, setStatusFilter] = useState(filters.status ?? 'pending');
  const [expandedId, setExpandedId] = useState<number | null>(null);
  const [scanResult, setScanResult] = useState<string | null>(null);

  const runScan = async () => {
    setScanning(true);
    setScanResult(null);
    try {
      const res = await fetch('/api/duplicate-check/auto-merge/scan', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN':
            (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
        },
      });
      const data = await res.json();
      setScanResult(
        `Created ${data.created} suggestions, skipped ${data.skipped}, evaluated ${data.evaluated} customers.`
      );
      router.reload();
    } catch {
      setScanResult('Scan failed. Please try again.');
    } finally {
      setScanning(false);
    }
  };

  const approve = async (id: number) => {
    setActioningId(id);
    try {
      await fetch(`/api/duplicate-check/auto-merge/${id}/approve`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN':
            (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
        },
      });
      router.reload();
    } finally {
      setActioningId(null);
    }
  };

  const reject = async (id: number) => {
    setActioningId(id);
    try {
      await fetch(`/api/duplicate-check/auto-merge/${id}/reject`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN':
            (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
        },
      });
      router.reload();
    } finally {
      setActioningId(null);
    }
  };

  const changeStatusFilter = (newStatus: string) => {
    setStatusFilter(newStatus);
    router.get(
      '/shop/duplicate-review/auto-merge',
      { status: newStatus },
      { preserveScroll: true }
    );
  };

  return (
    <AppLayout>
      <div className="space-y-6 p-6">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div>
            <h1 className="flex items-center gap-2 text-2xl font-bold">
              <GitMerge className="h-7 w-7 text-info" />
              Auto-Merge Suggestions
            </h1>
            <p className="mt-1 text-sm text-muted-foreground">
              High-confidence duplicate customer pairs recommended for automatic merging.
            </p>
          </div>
          <div className="flex gap-2">
            <Link href="/shop/duplicate-review">
              <Button variant="outline" size="sm">
                <ArrowLeft className="mr-1.5 h-4 w-4" />
                Back to Queue
              </Button>
            </Link>
            <Link href="/shop/duplicate-review/analytics">
              <Button variant="outline" size="sm">
                Analytics
              </Button>
            </Link>
          </div>
        </div>

        {/* Stats Cards */}
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <p className="text-xs text-muted-foreground">Pending</p>
                <AlertTriangle className="h-4 w-4 text-warning" />
              </div>
              <p className="text-2xl font-bold">{stats.pending}</p>
              <p className="text-xs text-muted-foreground">awaiting review</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <p className="text-xs text-muted-foreground">Merged</p>
                <Check className="h-4 w-4 text-green-500" />
              </div>
              <p className="text-2xl font-bold text-green-600">{stats.merged}</p>
              <p className="text-xs text-muted-foreground">successfully merged</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <p className="text-xs text-muted-foreground">Avg Confidence</p>
                <TrendingUp className="h-4 w-4 text-info" />
              </div>
              <p className="text-2xl font-bold">{stats.avg_confidence}%</p>
              <p className="text-xs text-muted-foreground">across pending</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <p className="text-xs text-muted-foreground">High Confidence</p>
                <Shield className="h-4 w-4 text-green-500" />
              </div>
              <p className="text-2xl font-bold text-green-600">{stats.by_confidence.high}</p>
              <p className="text-xs text-muted-foreground">
                {stats.by_confidence.medium} medium, {stats.by_confidence.low} low
              </p>
            </CardContent>
          </Card>
        </div>

        {/* Action Bar */}
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-2">
            <span className="text-sm text-muted-foreground">Status:</span>
            {['pending', 'merged', 'rejected'].map((s) => (
              <button
                key={s}
                onClick={() => changeStatusFilter(s)}
                className={`rounded-md px-3 py-1 text-sm capitalize ${
                  statusFilter === s
                    ? 'bg-primary text-primary-foreground'
                    : 'bg-muted text-muted-foreground hover:bg-muted/80'
                }`}
              >
                {s}
              </button>
            ))}
          </div>
          <Button onClick={runScan} disabled={scanning} size="sm">
            <RefreshCw className={`mr-1.5 h-4 w-4 ${scanning ? 'animate-spin' : ''}`} />
            {scanning ? 'Scanning...' : 'Run Scan'}
          </Button>
        </div>

        {scanResult && (
          <div className="rounded-md border border-info/20 bg-info/5 p-3 text-sm text-info">
            {scanResult}
          </div>
        )}

        {/* Suggestions List */}
        {suggestions.items.length > 0 ? (
          <div className="space-y-4">
            {suggestions.items.map((suggestion) => {
              const isExpanded = expandedId === suggestion.id;
              const preview = suggestion.merge_preview;
              return (
                <Card key={suggestion.id}>
                  <CardContent className="p-4">
                    {/* Top Row: Confidence + Match Reasons + Status */}
                    <div className="mb-3 flex items-center gap-3">
                      <Badge className={confidenceColor(suggestion.confidence_score)}>
                        {suggestion.confidence_score}% confidence
                      </Badge>
                      {suggestion.match_reasons.map((reason) => (
                        <Badge key={reason} className={matchReasonColor[reason] ?? ''}>
                          {reason}
                        </Badge>
                      ))}
                      {suggestion.status !== 'pending' && (
                        <Badge className={statusColor[suggestion.status] ?? ''}>
                          {suggestion.status}
                        </Badge>
                      )}
                      <span className="ml-auto text-xs text-muted-foreground">
                        {formatDateTime(suggestion.created_at)}
                      </span>
                    </div>

                    {/* Customer Pair */}
                    <div className="grid gap-4 sm:grid-cols-[1fr_auto_1fr]">
                      {/* Target */}
                      <div className="rounded-lg border bg-green-50/30 p-3">
                        <div className="mb-1 flex items-center gap-1.5">
                          <span className="text-xs font-medium text-green-700">TARGET (keep)</span>
                        </div>
                        <div className="space-y-1 text-sm">
                          <p className="font-medium">
                            {suggestion.target_customer?.name ?? 'Unknown'}
                          </p>
                          <p className="text-muted-foreground">
                            {suggestion.target_customer?.phone ?? '—'}
                          </p>
                          <div className="flex gap-3 text-xs text-muted-foreground">
                            <span>{suggestion.target_customer?.total_orders ?? 0} orders</span>
                            <span>{suggestion.target_customer?.risk_level ?? 'LOW'} risk</span>
                          </div>
                        </div>
                      </div>

                      {/* Merge Arrow */}
                      <div className="flex items-center justify-center">
                        <GitMerge className="h-6 w-6 text-muted-foreground" />
                      </div>

                      {/* Source */}
                      <div className="rounded-lg border bg-orange-50/30 p-3">
                        <div className="mb-1 flex items-center gap-1.5">
                          <span className="text-xs font-medium text-orange-700">
                            SOURCE (merge into target)
                          </span>
                        </div>
                        <div className="space-y-1 text-sm">
                          <p className="font-medium">
                            {suggestion.source_customer?.name ?? 'Unknown'}
                          </p>
                          <p className="text-muted-foreground">
                            {suggestion.source_customer?.phone ?? '—'}
                          </p>
                          <div className="flex gap-3 text-xs text-muted-foreground">
                            <span>{suggestion.source_customer?.total_orders ?? 0} orders</span>
                            <span>{suggestion.source_customer?.risk_level ?? 'LOW'} risk</span>
                          </div>
                        </div>
                      </div>
                    </div>

                    {/* Expandable Preview */}
                    {isExpanded && preview && preview.can_merge && (
                      <div className="mt-3 border-t pt-3">
                        <div className="grid gap-4 sm:grid-cols-2">
                          <div>
                            <p className="mb-2 text-xs font-medium text-muted-foreground">
                              Transfer Summary
                            </p>
                            <div className="grid grid-cols-3 gap-2 text-xs">
                              <div className="rounded bg-muted p-2">
                                <p className="text-muted-foreground">Orders</p>
                                <p className="font-bold">{preview.transfer_summary.orders}</p>
                              </div>
                              <div className="rounded bg-muted p-2">
                                <p className="text-muted-foreground">Conversations</p>
                                <p className="font-bold">
                                  {preview.transfer_summary.conversations}
                                </p>
                              </div>
                              <div className="rounded bg-muted p-2">
                                <p className="text-muted-foreground">Identities</p>
                                <p className="font-bold">{preview.transfer_summary.identities}</p>
                              </div>
                              <div className="rounded bg-muted p-2">
                                <p className="text-muted-foreground">Addresses</p>
                                <p className="font-bold">{preview.transfer_summary.addresses}</p>
                              </div>
                              <div className="rounded bg-muted p-2">
                                <p className="text-muted-foreground">Notes</p>
                                <p className="font-bold">{preview.transfer_summary.notes}</p>
                              </div>
                              <div className="rounded bg-muted p-2">
                                <p className="text-muted-foreground">Leads</p>
                                <p className="font-bold">{preview.transfer_summary.leads}</p>
                              </div>
                            </div>
                          </div>
                          <div>
                            <p className="mb-2 text-xs font-medium text-muted-foreground">
                              Merged Result
                            </p>
                            <div className="space-y-1 text-xs">
                              <div className="flex justify-between">
                                <span className="text-muted-foreground">Total Orders</span>
                                <span className="font-medium">
                                  {preview.merged_stats.total_orders}
                                </span>
                              </div>
                              <div className="flex justify-between">
                                <span className="text-muted-foreground">Successful Orders</span>
                                <span className="font-medium">
                                  {preview.merged_stats.successful_orders}
                                </span>
                              </div>
                              <div className="flex justify-between">
                                <span className="text-muted-foreground">Returned Orders</span>
                                <span className="font-medium">
                                  {preview.merged_stats.returned_orders}
                                </span>
                              </div>
                              <div className="flex justify-between">
                                <span className="text-muted-foreground">Total Revenue</span>
                                <span className="font-medium">
                                  ₱{preview.merged_stats.total_revenue.toLocaleString()}
                                </span>
                              </div>
                              {preview.risk_will_change && (
                                <div className="mt-2 rounded bg-warning/10 p-2 text-warning">
                                  Risk level will change to {preview.new_risk_level}
                                </div>
                              )}
                              {preview.filled_fields.length > 0 && (
                                <div className="mt-1 text-muted-foreground">
                                  Fields to fill: {preview.filled_fields.join(', ')}
                                </div>
                              )}
                            </div>
                          </div>
                        </div>
                      </div>
                    )}

                    {/* Actioned Info */}
                    {suggestion.status !== 'pending' && suggestion.actioner && (
                      <div className="mt-3 border-t pt-2 text-xs text-muted-foreground">
                        {suggestion.status} by {suggestion.actioner.name}
                        {suggestion.actioned_at && ` on ${formatDateTime(suggestion.actioned_at)}`}
                        {suggestion.action_note && ` — "${suggestion.action_note}"`}
                      </div>
                    )}

                    {/* Action Buttons */}
                    {suggestion.status === 'pending' && (
                      <div className="mt-3 flex items-center gap-2">
                        <Button
                          size="sm"
                          variant="outline"
                          onClick={() => setExpandedId(isExpanded ? null : suggestion.id)}
                        >
                          {isExpanded ? 'Hide Preview' : 'Show Preview'}
                        </Button>
                        <Button
                          size="sm"
                          variant="default"
                          className="bg-green-600 hover:bg-green-700"
                          onClick={() => approve(suggestion.id)}
                          disabled={actioningId === suggestion.id}
                        >
                          <Check className="mr-1 h-4 w-4" />
                          Approve & Merge
                        </Button>
                        <Button
                          size="sm"
                          variant="outline"
                          onClick={() => reject(suggestion.id)}
                          disabled={actioningId === suggestion.id}
                        >
                          <X className="mr-1 h-4 w-4" />
                          Reject
                        </Button>
                      </div>
                    )}
                  </CardContent>
                </Card>
              );
            })}

            {/* Pagination */}
            {suggestions.meta.last_page > 1 && (
              <div className="flex items-center justify-center gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  disabled={suggestions.meta.current_page <= 1}
                  onClick={() =>
                    router.get(
                      '/shop/duplicate-review/auto-merge',
                      { status: statusFilter, page: suggestions.meta.current_page - 1 },
                      { preserveScroll: true }
                    )
                  }
                >
                  Previous
                </Button>
                <span className="text-sm text-muted-foreground">
                  Page {suggestions.meta.current_page} of {suggestions.meta.last_page} (
                  {suggestions.meta.total} total)
                </span>
                <Button
                  variant="outline"
                  size="sm"
                  disabled={suggestions.meta.current_page >= suggestions.meta.last_page}
                  onClick={() =>
                    router.get(
                      '/shop/duplicate-review/auto-merge',
                      { status: statusFilter, page: suggestions.meta.current_page + 1 },
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
              <Users className="mb-3 h-12 w-12 text-muted-foreground" />
              <p className="text-sm text-muted-foreground">
                No auto-merge suggestions found. Run a scan to detect high-confidence duplicates.
              </p>
              <Button onClick={runScan} disabled={scanning} size="sm" className="mt-4">
                <RefreshCw className={`mr-1.5 h-4 w-4 ${scanning ? 'animate-spin' : ''}`} />
                {scanning ? 'Scanning...' : 'Run Scan Now'}
              </Button>
            </CardContent>
          </Card>
        )}
      </div>
    </AppLayout>
  );
}
