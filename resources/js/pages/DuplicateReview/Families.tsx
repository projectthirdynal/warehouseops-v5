import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { AlertTriangle, ArrowLeft, RefreshCw, Users, GitMerge, X, Eye, Crown } from 'lucide-react';
import { formatDateTime } from '@/lib/utils';

interface Family {
  id: number;
  type: string;
  group_key: string;
  group_method: string;
  anchor_ref_id: number;
  anchor_label: string;
  member_count: number;
  merged_count: number;
  status: string;
  metadata: Record<string, unknown>;
  created_at: string;
  members_count: number;
  actioner: { id: number; name: string } | null;
}

interface Stats {
  total: number;
  active: number;
  merged: number;
  dismissed: number;
  by_method: Record<string, number>;
  total_members: number;
  active_members: number;
  avg_family_size: number;
  largest_family: { id: number; label: string; members: number } | null;
}

interface FamilyMember {
  id: number;
  customer_id: number;
  is_anchor: boolean;
  match_reason: string;
  similarity_score: number;
  name: string;
  facebook_name: string;
  phone: string;
  total_orders: number;
  total_revenue: number;
  risk_level: string;
  is_blacklisted: boolean;
  created_at: string;
  merge_preview: {
    can_merge: boolean;
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
    risk_will_change: boolean;
    new_risk_level: string;
    filled_fields: string[];
  } | null;
}

interface FamilyDetail {
  id: number;
  type: string;
  group_key: string;
  group_method: string;
  anchor_ref_id: number;
  anchor_label: string;
  member_count: number;
  merged_count: number;
  status: string;
  metadata: Record<string, unknown>;
  actioned_at: string | null;
  action_note: string | null;
  actioner_name: string | null;
  created_at: string;
  members: FamilyMember[];
}

interface Props {
  families: {
    items: Family[];
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
  filters: { status?: string; method?: string; min_members?: string };
}

const methodColor: Record<string, string> = {
  phone: 'bg-blue-100 text-blue-700',
  psid: 'bg-purple-100 text-purple-700',
};

const statusColor: Record<string, string> = {
  active: 'bg-secondary text-secondary-foreground',
  merged: 'bg-green-100 text-green-700',
  dismissed: 'bg-red-100 text-red-700',
};

export default function DuplicateReviewFamilies({ families, stats, filters }: Props) {
  const [building, setBuilding] = useState(false);
  const [actioningId, setActioningId] = useState<number | null>(null);
  const [statusFilter, setStatusFilter] = useState(filters.status ?? 'active');
  const [methodFilter, setMethodFilter] = useState(filters.method ?? '');
  const [detailFamily, setDetailFamily] = useState<FamilyDetail | null>(null);
  const [loadingDetail, setLoadingDetail] = useState<number | null>(null);
  const [buildResult, setBuildResult] = useState<string | null>(null);

  const csrfToken =
    (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '';

  const build = async () => {
    setBuilding(true);
    setBuildResult(null);
    try {
      const res = await fetch('/api/duplicate-check/families/build', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
      });
      const data = await res.json();
      setBuildResult(
        `Created ${data.created} families, skipped ${data.skipped}, grouped ${data.members_grouped} members.`
      );
      router.reload();
    } catch {
      setBuildResult('Build failed. Please try again.');
    } finally {
      setBuilding(false);
    }
  };

  const viewDetail = async (id: number) => {
    setLoadingDetail(id);
    try {
      const res = await fetch(`/api/duplicate-check/families/${id}`);
      const data = await res.json();
      setDetailFamily(data);
    } finally {
      setLoadingDetail(null);
    }
  };

  const mergeFamily = async (id: number) => {
    setActioningId(id);
    try {
      await fetch(`/api/duplicate-check/families/${id}/merge`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
      });
      setDetailFamily(null);
      router.reload();
    } finally {
      setActioningId(null);
    }
  };

  const dismissFamily = async (id: number) => {
    setActioningId(id);
    try {
      await fetch(`/api/duplicate-check/families/${id}/dismiss`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
      });
      setDetailFamily(null);
      router.reload();
    } finally {
      setActioningId(null);
    }
  };

  const changeStatusFilter = (newStatus: string) => {
    setStatusFilter(newStatus);
    router.get(
      '/shop/duplicate-review/families',
      { status: newStatus, method: methodFilter || undefined },
      { preserveScroll: true }
    );
  };

  const changeMethodFilter = (newMethod: string) => {
    setMethodFilter(newMethod);
    router.get(
      '/shop/duplicate-review/families',
      { status: statusFilter, method: newMethod || undefined },
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
              <Users className="h-7 w-7 text-info" />
              Duplicate Families
            </h1>
            <p className="mt-1 text-sm text-muted-foreground">
              Groups of duplicate customers sharing the same phone or PSID. Merge entire families in
              one action.
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
          </div>
        </div>

        {/* Stats Cards */}
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <p className="text-xs text-muted-foreground">Active Families</p>
                <AlertTriangle className="h-4 w-4 text-warning" />
              </div>
              <p className="text-2xl font-bold">{stats.active}</p>
              <p className="text-xs text-muted-foreground">{stats.active_members} members total</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <p className="text-xs text-muted-foreground">Merged</p>
                <GitMerge className="h-4 w-4 text-green-500" />
              </div>
              <p className="text-2xl font-bold text-green-600">{stats.merged}</p>
              <p className="text-xs text-muted-foreground">families resolved</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <p className="text-xs text-muted-foreground">Avg Family Size</p>
                <Users className="h-4 w-4 text-info" />
              </div>
              <p className="text-2xl font-bold">{stats.avg_family_size}</p>
              <p className="text-xs text-muted-foreground">members per family</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <p className="text-xs text-muted-foreground">Largest Family</p>
                <Crown className="h-4 w-4 text-yellow-500" />
              </div>
              <p className="truncate text-lg font-bold">{stats.largest_family?.label ?? '—'}</p>
              <p className="text-xs text-muted-foreground">
                {stats.largest_family?.members ?? 0} members
              </p>
            </CardContent>
          </Card>
        </div>

        {/* Action Bar */}
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-4">
            <div className="flex items-center gap-2">
              <span className="text-sm text-muted-foreground">Status:</span>
              {['active', 'merged', 'dismissed'].map((s) => (
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
            <div className="flex items-center gap-2">
              <span className="text-sm text-muted-foreground">Method:</span>
              {['', 'phone', 'psid'].map((m) => (
                <button
                  key={m || 'all'}
                  onClick={() => changeMethodFilter(m)}
                  className={`rounded-md px-3 py-1 text-sm capitalize ${
                    methodFilter === m
                      ? 'bg-primary text-primary-foreground'
                      : 'bg-muted text-muted-foreground hover:bg-muted/80'
                  }`}
                >
                  {m || 'All'}
                </button>
              ))}
            </div>
          </div>
          <Button onClick={build} disabled={building} size="sm">
            <RefreshCw className={`mr-1.5 h-4 w-4 ${building ? 'animate-spin' : ''}`} />
            {building ? 'Building...' : 'Build Families'}
          </Button>
        </div>

        {buildResult && (
          <div className="rounded-md border border-info/20 bg-info/5 p-3 text-sm text-info">
            {buildResult}
          </div>
        )}

        {/* Detail Modal */}
        {detailFamily && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <Card className="max-h-[80vh] w-full max-w-3xl overflow-y-auto">
              <CardContent className="p-6">
                <div className="mb-4 flex items-center justify-between">
                  <div>
                    <h2 className="text-lg font-bold">{detailFamily.anchor_label}</h2>
                    <div className="mt-1 flex items-center gap-2">
                      <Badge className={methodColor[detailFamily.group_method] ?? ''}>
                        {detailFamily.group_method}
                      </Badge>
                      <Badge className={statusColor[detailFamily.status] ?? ''}>
                        {detailFamily.status}
                      </Badge>
                      <span className="text-xs text-muted-foreground">
                        {detailFamily.member_count} members
                      </span>
                    </div>
                  </div>
                  <Button variant="ghost" size="sm" onClick={() => setDetailFamily(null)}>
                    <X className="h-4 w-4" />
                  </Button>
                </div>

                {/* Members */}
                <div className="space-y-3">
                  {detailFamily.members.map((member) => (
                    <div
                      key={member.id}
                      className={`rounded-lg border p-3 ${
                        member.is_anchor
                          ? 'border-green-300 bg-green-50/30'
                          : 'border-orange-200 bg-orange-50/20'
                      }`}
                    >
                      <div className="flex items-center justify-between">
                        <div className="flex items-center gap-2">
                          {member.is_anchor && <Crown className="h-4 w-4 text-yellow-500" />}
                          <div>
                            <p className="text-sm font-medium">
                              {member.name ??
                                member.facebook_name ??
                                `Customer #${member.customer_id}`}
                            </p>
                            <p className="text-xs text-muted-foreground">
                              {member.phone ?? '—'} · {member.total_orders} orders ·{' '}
                              {member.risk_level} risk
                            </p>
                          </div>
                        </div>
                        <div className="flex items-center gap-2">
                          {member.is_anchor ? (
                            <Badge className="bg-green-100 text-green-700">ANCHOR</Badge>
                          ) : (
                            <Badge className="bg-orange-100 text-orange-700">
                              {member.match_reason}
                            </Badge>
                          )}
                        </div>
                      </div>

                      {/* Merge preview for non-anchor members */}
                      {!member.is_anchor && member.merge_preview?.can_merge && (
                        <div className="mt-2 border-t pt-2">
                          <div className="grid grid-cols-6 gap-2 text-xs">
                            <div className="rounded bg-muted p-1.5 text-center">
                              <p className="text-muted-foreground">Orders</p>
                              <p className="font-bold">
                                {member.merge_preview.transfer_summary.orders}
                              </p>
                            </div>
                            <div className="rounded bg-muted p-1.5 text-center">
                              <p className="text-muted-foreground">Convos</p>
                              <p className="font-bold">
                                {member.merge_preview.transfer_summary.conversations}
                              </p>
                            </div>
                            <div className="rounded bg-muted p-1.5 text-center">
                              <p className="text-muted-foreground">IDs</p>
                              <p className="font-bold">
                                {member.merge_preview.transfer_summary.identities}
                              </p>
                            </div>
                            <div className="rounded bg-muted p-1.5 text-center">
                              <p className="text-muted-foreground">Addr</p>
                              <p className="font-bold">
                                {member.merge_preview.transfer_summary.addresses}
                              </p>
                            </div>
                            <div className="rounded bg-muted p-1.5 text-center">
                              <p className="text-muted-foreground">Notes</p>
                              <p className="font-bold">
                                {member.merge_preview.transfer_summary.notes}
                              </p>
                            </div>
                            <div className="rounded bg-muted p-1.5 text-center">
                              <p className="text-muted-foreground">Leads</p>
                              <p className="font-bold">
                                {member.merge_preview.transfer_summary.leads}
                              </p>
                            </div>
                          </div>
                          {member.merge_preview.risk_will_change && (
                            <p className="mt-1 text-xs text-warning">
                              Risk will change to {member.merge_preview.new_risk_level}
                            </p>
                          )}
                        </div>
                      )}
                    </div>
                  ))}
                </div>

                {/* Actions */}
                {detailFamily.status === 'active' && (
                  <div className="mt-4 flex items-center gap-2 border-t pt-4">
                    <Button
                      size="sm"
                      className="bg-green-600 hover:bg-green-700"
                      onClick={() => mergeFamily(detailFamily.id)}
                      disabled={actioningId === detailFamily.id}
                    >
                      <GitMerge className="mr-1.5 h-4 w-4" />
                      Merge All into Anchor
                    </Button>
                    <Button
                      size="sm"
                      variant="outline"
                      onClick={() => dismissFamily(detailFamily.id)}
                      disabled={actioningId === detailFamily.id}
                    >
                      <X className="mr-1.5 h-4 w-4" />
                      Dismiss
                    </Button>
                  </div>
                )}

                {detailFamily.status !== 'active' && detailFamily.actioner_name && (
                  <div className="mt-4 border-t pt-2 text-xs text-muted-foreground">
                    {detailFamily.status} by {detailFamily.actioner_name}
                    {detailFamily.actioned_at && ` on ${formatDateTime(detailFamily.actioned_at)}`}
                    {detailFamily.action_note && ` — "${detailFamily.action_note}"`}
                  </div>
                )}
              </CardContent>
            </Card>
          </div>
        )}

        {/* Families List */}
        {families.items.length > 0 ? (
          <div className="space-y-3">
            {families.items.map((family) => (
              <Card key={family.id}>
                <CardContent className="p-4">
                  <div className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                      <div className="flex h-10 w-10 items-center justify-center rounded-full bg-muted">
                        <Users className="h-5 w-5 text-muted-foreground" />
                      </div>
                      <div>
                        <p className="font-medium">{family.anchor_label}</p>
                        <div className="mt-0.5 flex items-center gap-2">
                          <Badge className={methodColor[family.group_method] ?? ''}>
                            {family.group_method}
                          </Badge>
                          <Badge className={statusColor[family.status] ?? ''}>
                            {family.status}
                          </Badge>
                          <span className="text-xs text-muted-foreground">
                            {family.member_count} members
                            {family.merged_count > 0 && ` · ${family.merged_count} merged`}
                          </span>
                        </div>
                      </div>
                    </div>
                    <div className="flex items-center gap-2">
                      <span className="text-xs text-muted-foreground">
                        {formatDateTime(family.created_at)}
                      </span>
                      {family.status === 'active' && (
                        <>
                          <Button
                            size="sm"
                            variant="outline"
                            onClick={() => viewDetail(family.id)}
                            disabled={loadingDetail === family.id}
                          >
                            <Eye className="mr-1 h-4 w-4" />
                            View
                          </Button>
                          <Button
                            size="sm"
                            className="bg-green-600 hover:bg-green-700"
                            onClick={() => mergeFamily(family.id)}
                            disabled={actioningId === family.id}
                          >
                            <GitMerge className="mr-1 h-4 w-4" />
                            Merge All
                          </Button>
                          <Button
                            size="sm"
                            variant="outline"
                            onClick={() => dismissFamily(family.id)}
                            disabled={actioningId === family.id}
                          >
                            <X className="h-4 w-4" />
                          </Button>
                        </>
                      )}
                      {family.status !== 'active' && (
                        <Button
                          size="sm"
                          variant="outline"
                          onClick={() => viewDetail(family.id)}
                          disabled={loadingDetail === family.id}
                        >
                          <Eye className="mr-1 h-4 w-4" />
                          View
                        </Button>
                      )}
                    </div>
                  </div>

                  {/* Metadata summary */}
                  {family.metadata && typeof family.metadata === 'object' && (
                    <div className="mt-2 flex gap-4 text-xs text-muted-foreground">
                      {'total_orders' in family.metadata && (
                        <span>{String(family.metadata.total_orders)} total orders</span>
                      )}
                      {'total_revenue' in family.metadata && (
                        <span>
                          ₱{Number(family.metadata.total_revenue).toLocaleString()} revenue
                        </span>
                      )}
                      {'phone' in family.metadata && (
                        <span>phone: {String(family.metadata.phone)}</span>
                      )}
                      {'psid' in family.metadata && (
                        <span>psid: {String(family.metadata.psid).substring(0, 12)}...</span>
                      )}
                    </div>
                  )}

                  {/* Actioned info */}
                  {family.status !== 'active' && family.actioner && (
                    <div className="mt-2 border-t pt-1 text-xs text-muted-foreground">
                      {family.status} by {family.actioner.name}
                    </div>
                  )}
                </CardContent>
              </Card>
            ))}

            {/* Pagination */}
            {families.meta.last_page > 1 && (
              <div className="flex items-center justify-center gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  disabled={families.meta.current_page <= 1}
                  onClick={() =>
                    router.get(
                      '/shop/duplicate-review/families',
                      {
                        status: statusFilter,
                        method: methodFilter || undefined,
                        page: families.meta.current_page - 1,
                      },
                      { preserveScroll: true }
                    )
                  }
                >
                  Previous
                </Button>
                <span className="text-sm text-muted-foreground">
                  Page {families.meta.current_page} of {families.meta.last_page} (
                  {families.meta.total} total)
                </span>
                <Button
                  variant="outline"
                  size="sm"
                  disabled={families.meta.current_page >= families.meta.last_page}
                  onClick={() =>
                    router.get(
                      '/shop/duplicate-review/families',
                      {
                        status: statusFilter,
                        method: methodFilter || undefined,
                        page: families.meta.current_page + 1,
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
              <Users className="mb-3 h-12 w-12 text-muted-foreground" />
              <p className="text-sm text-muted-foreground">
                No duplicate families found. Run a build to group customers by phone or PSID.
              </p>
              <Button onClick={build} disabled={building} size="sm" className="mt-4">
                <RefreshCw className={`mr-1.5 h-4 w-4 ${building ? 'animate-spin' : ''}`} />
                {building ? 'Building...' : 'Build Families Now'}
              </Button>
            </CardContent>
          </Card>
        )}
      </div>
    </AppLayout>
  );
}
