import { Head, router } from '@inertiajs/react';
import { FilePlus2, Eye } from 'lucide-react';
import TelesalesLayout from '@/layouts/TelesalesLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';

interface PoolRequest {
  id: number;
  request_number: string;
  brand_name: string;
  business_region: string | null;
  province: string | null;
  lead_age_from: number;
  lead_age_to: number | null;
  requested_quantity: number;
  available_quantity_at_request: number;
  approved_quantity: number | null;
  status: string;
  created_at: string;
  requested_by: { id: number; name: string };
  approved_by: { id: number; name: string } | null;
  pool: { id: number; pool_number: string; status: string } | null;
}

interface PaginatedRequests {
  data: PoolRequest[];
  current_page: number;
  last_page: number;
  total: number;
  from: number | null;
  to: number | null;
  links: Array<{ url: string | null; label: string; active: boolean }>;
}

interface StatusOption {
  value: string;
  label: string;
}

interface Props {
  requests: PaginatedRequests;
  statusFilter: string;
  statusOptions: StatusOption[];
}

const statusColors: Record<string, string> = {
  DRAFT: 'bg-gray-100 text-gray-800',
  PENDING_APPROVAL: 'bg-yellow-100 text-yellow-800',
  APPROVED: 'bg-green-100 text-green-800',
  REJECTED: 'bg-red-100 text-red-800',
  CANCELLED: 'bg-gray-100 text-gray-500',
  PARTIALLY_DISTRIBUTED: 'bg-blue-100 text-blue-800',
  DISTRIBUTED: 'bg-emerald-100 text-emerald-800',
};

export default function PoolRequestsIndex({ requests, statusFilter, statusOptions }: Props) {
  const formatDate = (d: string) =>
    new Date(d).toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' });

  return (
    <TelesalesLayout>
      <Head title="Pool Requests — Telesales" />
      <div className="space-y-6 p-6">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold tracking-tight">Pool Requests</h1>
            <p className="text-sm text-muted-foreground mt-1">
              Submit and track lead pool requests for approval.
            </p>
          </div>
          <Button onClick={() => router.visit('/telesales/pool-requests/create')}>
            <FilePlus2 className="mr-2 h-4 w-4" />
            New Pool Request
          </Button>
        </div>

        <div className="flex items-center gap-4">
          <Select
            value={statusFilter}
            onValueChange={(v) =>
              router.get('/telesales/pool-requests', { status: v }, { preserveState: true })
            }
          >
            <SelectTrigger className="w-[200px]">
              <SelectValue placeholder="All Statuses" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All Statuses</SelectItem>
              {statusOptions.map((s) => (
                <SelectItem key={s.value} value={s.value}>
                  {s.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        <Card>
          <CardHeader>
            <CardTitle>Requests ({requests.total})</CardTitle>
          </CardHeader>
          <CardContent>
            {requests.data.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-12 text-center">
                <FilePlus2 className="h-12 w-12 text-muted-foreground mb-3" />
                <p className="text-muted-foreground">No pool requests yet.</p>
                <Button
                  className="mt-4"
                  onClick={() => router.visit('/telesales/pool-requests/create')}
                >
                  <FilePlus2 className="mr-2 h-4 w-4" />
                  Create First Request
                </Button>
              </div>
            ) : (
              <>
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="border-b text-left">
                        <th className="pb-3 pr-4 font-medium">Request #</th>
                        <th className="pb-3 pr-4 font-medium">Brand</th>
                        <th className="pb-3 pr-4 font-medium">Region</th>
                        <th className="pb-3 pr-4 font-medium">Age</th>
                        <th className="pb-3 pr-4 text-right font-medium">Requested</th>
                        <th className="pb-3 pr-4 text-right font-medium">Available</th>
                        <th className="pb-3 pr-4 font-medium">Status</th>
                        <th className="pb-3 pr-4 font-medium">Requested By</th>
                        <th className="pb-3 pr-4 font-medium">Date</th>
                        <th className="pb-3 font-medium"></th>
                      </tr>
                    </thead>
                    <tbody>
                      {requests.data.map((req) => (
                        <tr key={req.id} className="border-b last:border-0 hover:bg-muted/50">
                          <td className="py-3 pr-4 font-mono text-xs">{req.request_number}</td>
                          <td className="py-3 pr-4 font-medium">{req.brand_name}</td>
                          <td className="py-3 pr-4">
                            {req.business_region || req.province || 'All'}
                          </td>
                          <td className="py-3 pr-4 text-xs">
                            {req.lead_age_from}–{req.lead_age_to ?? 60}d
                          </td>
                          <td className="py-3 pr-4 text-right tabular-nums">
                            {req.requested_quantity.toLocaleString()}
                          </td>
                          <td className="py-3 pr-4 text-right tabular-nums text-muted-foreground">
                            {req.available_quantity_at_request.toLocaleString()}
                          </td>
                          <td className="py-3 pr-4">
                            <Badge
                              className={statusColors[req.status] ?? 'bg-gray-100'}
                              variant="secondary"
                            >
                              {req.status.replace(/_/g, ' ')}
                            </Badge>
                          </td>
                          <td className="py-3 pr-4 text-xs">{req.requested_by?.name ?? '—'}</td>
                          <td className="py-3 pr-4 text-xs text-muted-foreground">
                            {formatDate(req.created_at)}
                          </td>
                          <td className="py-3">
                            <Button
                              size="sm"
                              variant="ghost"
                              onClick={() => router.visit(`/telesales/pool-requests/${req.id}`)}
                            >
                              <Eye className="h-4 w-4" />
                            </Button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
                {requests.last_page > 1 && (
                  <div className="flex items-center justify-between mt-4">
                    <p className="text-xs text-muted-foreground">
                      Showing {requests.from}–{requests.to} of {requests.total}
                    </p>
                    <div className="flex gap-1">
                      {requests.links.map((link, i) => (
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
              </>
            )}
          </CardContent>
        </Card>
      </div>
    </TelesalesLayout>
  );
}
