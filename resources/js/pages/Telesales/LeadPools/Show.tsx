import { Head, router } from '@inertiajs/react';
import { ArrowLeft, XCircle } from 'lucide-react';
import TelesalesLayout from '@/layouts/TelesalesLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

interface LeadPool {
  id: number;
  pool_number: string;
  brand_name: string;
  product_name: string | null;
  business_region: string | null;
  province: string | null;
  city: string | null;
  lead_age_from: number;
  lead_age_to: number | null;
  approved_quantity: number;
  reserved_quantity: number;
  distributed_quantity: number;
  distribution_method: string;
  status: string;
  created_at: string;
  activated_at: string | null;
  completed_at: string | null;
  cancelled_at: string | null;
  request: { id: number; request_number: string; requested_by: { name: string } } | null;
  created_by: { id: number; name: string } | null;
  approved_by: { id: number; name: string } | null;
}

interface PoolMember {
  id: number;
  status: string;
  added_at: string;
  assigned_at: string | null;
  lead: {
    id: number;
    name: string;
    phone: string;
    product_name: string;
    city: string | null;
    customer: { id: number; name: string } | null;
  };
}

interface PaginatedMembers {
  data: PoolMember[];
  total: number;
  current_page: number;
  last_page: number;
  from: number | null;
  to: number | null;
  links: Array<{ url: string | null; label: string; active: boolean }>;
}

interface Props {
  pool: LeadPool;
  members: PaginatedMembers;
}

const memberStatusColors: Record<string, string> = {
  PENDING: 'bg-yellow-100 text-yellow-800',
  ASSIGNED: 'bg-green-100 text-green-800',
  REMOVED: 'bg-gray-100 text-gray-500',
  SKIPPED: 'bg-orange-100 text-orange-800',
};

export default function LeadPoolShow({ pool, members }: Props) {
  const remaining = pool.reserved_quantity - pool.distributed_quantity;
  const fmt = (d: string | null) =>
    d ? new Date(d).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' }) : '—';

  const isActive = ['READY', 'ACTIVE', 'PARTIALLY_DISTRIBUTED'].includes(pool.status);

  return (
    <TelesalesLayout>
      <Head title={`Pool ${pool.pool_number}`} />
      <div className="space-y-6 p-6">
        <div className="flex items-center gap-4">
          <Button variant="ghost" size="sm" onClick={() => router.visit('/telesales/pools')}>
            <ArrowLeft className="mr-2 h-4 w-4" />
            Back
          </Button>
          <div>
            <h1 className="text-2xl font-bold tracking-tight font-mono">{pool.pool_number}</h1>
            <Badge className="mt-1" variant="secondary">
              {pool.status.replace(/_/g, ' ')}
            </Badge>
          </div>
        </div>

        {/* Summary cards */}
        <div className="grid gap-4 md:grid-cols-4">
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium">Reserved</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{pool.reserved_quantity.toLocaleString()}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium">Distributed</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-green-600">
                {pool.distributed_quantity.toLocaleString()}
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium">Remaining</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-orange-600">{remaining.toLocaleString()}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium">Approved Qty</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{pool.approved_quantity.toLocaleString()}</div>
            </CardContent>
          </Card>
        </div>

        <div className="grid gap-6 md:grid-cols-2">
          <Card>
            <CardHeader>
              <CardTitle>Pool Details</CardTitle>
            </CardHeader>
            <CardContent className="space-y-2 text-sm">
              <Row label="Brand" value={pool.brand_name} />
              <Row label="Product" value={pool.product_name || 'All'} />
              <Row label="Region" value={pool.business_region || 'All'} />
              <Row label="Province" value={pool.province || 'All'} />
              <Row
                label="Age Range"
                value={`${pool.lead_age_from}–${pool.lead_age_to ?? 60} days`}
              />
              <Row label="Distribution" value={pool.distribution_method} />
              <Row label="Created By" value={pool.created_by?.name ?? '—'} />
              <Row label="Approved By" value={pool.approved_by?.name ?? '—'} />
              <Row label="Activated" value={fmt(pool.activated_at)} />
              <Row label="Completed" value={fmt(pool.completed_at)} />
              {pool.request && (
                <div className="pt-2">
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => router.visit(`/telesales/pool-requests/${pool.request!.id}`)}
                  >
                    View Original Request ({pool.request.request_number})
                  </Button>
                </div>
              )}
            </CardContent>
          </Card>
        </div>

        {/* Members table */}
        <Card>
          <CardHeader>
            <CardTitle>Pool Members ({members.total})</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b text-left">
                    <th className="pb-3 pr-4 font-medium">Lead</th>
                    <th className="pb-3 pr-4 font-medium">Phone</th>
                    <th className="pb-3 pr-4 font-medium">Product</th>
                    <th className="pb-3 pr-4 font-medium">City</th>
                    <th className="pb-3 pr-4 font-medium">Status</th>
                    <th className="pb-3 pr-4 font-medium">Added</th>
                    <th className="pb-3 pr-4 font-medium">Assigned</th>
                  </tr>
                </thead>
                <tbody>
                  {members.data.map((m) => (
                    <tr key={m.id} className="border-b last:border-0 hover:bg-muted/50">
                      <td className="py-3 pr-4 font-medium">{m.lead.name}</td>
                      <td className="py-3 pr-4 font-mono text-xs">{m.lead.phone}</td>
                      <td className="py-3 pr-4 text-xs">{m.lead.product_name}</td>
                      <td className="py-3 pr-4 text-xs">{m.lead.city || '—'}</td>
                      <td className="py-3 pr-4">
                        <Badge
                          className={memberStatusColors[m.status] ?? 'bg-gray-100'}
                          variant="secondary"
                        >
                          {m.status}
                        </Badge>
                      </td>
                      <td className="py-3 pr-4 text-xs text-muted-foreground">{fmt(m.added_at)}</td>
                      <td className="py-3 pr-4 text-xs text-muted-foreground">
                        {fmt(m.assigned_at)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            {members.last_page > 1 && (
              <div className="flex items-center justify-between mt-4">
                <p className="text-xs text-muted-foreground">
                  Showing {members.from}–{members.to} of {members.total}
                </p>
                <div className="flex gap-1">
                  {members.links.map((link, i) => (
                    <Button
                      key={i}
                      size="sm"
                      variant={link.active ? 'default' : 'outline'}
                      disabled={!link.url}
                      onClick={() => link.url && router.visit(link.url)}
                      dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                  ))}
                </div>
              </div>
            )}
          </CardContent>
        </Card>

        {/* Cancel button */}
        {isActive && (
          <div className="flex justify-end">
            <Button
              variant="outline"
              onClick={() => {
                if (
                  confirm(
                    'Cancel this pool? Pending members will be removed. Assigned members stay for audit.'
                  )
                ) {
                  router.post(`/telesales/pools/${pool.id}/cancel`, {}, { preserveState: false });
                }
              }}
            >
              <XCircle className="mr-2 h-4 w-4" />
              Cancel Pool
            </Button>
          </div>
        )}
      </div>
    </TelesalesLayout>
  );
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex justify-between">
      <span className="text-muted-foreground">{label}</span>
      <span className="font-medium">{value}</span>
    </div>
  );
}
