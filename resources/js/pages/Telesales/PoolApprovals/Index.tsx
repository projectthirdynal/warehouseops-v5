import { Head, router } from '@inertiajs/react';
import { CheckCircle2, Eye } from 'lucide-react';
import TelesalesLayout from '@/layouts/TelesalesLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

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
  current_available: number;
  status: string;
  created_at: string;
  requested_by: { id: number; name: string };
  pool: { id: number; pool_number: string } | null;
}

interface PaginatedRequests {
  data: PoolRequest[];
  total: number;
  current_page: number;
  last_page: number;
  from: number | null;
  to: number | null;
  links: Array<{ url: string | null; label: string; active: boolean }>;
}

interface Props {
  requests: PaginatedRequests;
}

export default function PoolApprovalsIndex({ requests }: Props) {
  const fmt = (d: string) =>
    new Date(d).toLocaleDateString('en-PH', {
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    });

  return (
    <TelesalesLayout>
      <Head title="Pool Approvals — Telesales" />
      <div className="space-y-6 p-6">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Pool Approvals</h1>
          <p className="text-sm text-muted-foreground mt-1">
            Review and approve pending lead pool requests.
          </p>
        </div>

        <Card>
          <CardHeader>
            <CardTitle>Pending Approvals ({requests.total})</CardTitle>
          </CardHeader>
          <CardContent>
            {requests.data.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-12 text-center">
                <CheckCircle2 className="h-12 w-12 text-green-500 mb-3" />
                <p className="text-muted-foreground">No pending approvals. All caught up!</p>
              </div>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b text-left">
                      <th className="pb-3 pr-4 font-medium">Request #</th>
                      <th className="pb-3 pr-4 font-medium">Brand</th>
                      <th className="pb-3 pr-4 font-medium">Region</th>
                      <th className="pb-3 pr-4 text-right font-medium">Requested</th>
                      <th className="pb-3 pr-4 text-right font-medium">At Request</th>
                      <th className="pb-3 pr-4 text-right font-medium">Now</th>
                      <th className="pb-3 pr-4 font-medium">Can Fulfill</th>
                      <th className="pb-3 pr-4 font-medium">Requested By</th>
                      <th className="pb-3 pr-4 font-medium">Date</th>
                      <th className="pb-3 font-medium"></th>
                    </tr>
                  </thead>
                  <tbody>
                    {requests.data.map((req) => {
                      const canFulfill = req.current_available >= req.requested_quantity;
                      return (
                        <tr key={req.id} className="border-b last:border-0 hover:bg-muted/50">
                          <td className="py-3 pr-4 font-mono text-xs">{req.request_number}</td>
                          <td className="py-3 pr-4 font-medium">{req.brand_name}</td>
                          <td className="py-3 pr-4">
                            {req.business_region || req.province || 'All'}
                          </td>
                          <td className="py-3 pr-4 text-right tabular-nums font-medium">
                            {req.requested_quantity.toLocaleString()}
                          </td>
                          <td className="py-3 pr-4 text-right tabular-nums text-muted-foreground">
                            {req.available_quantity_at_request.toLocaleString()}
                          </td>
                          <td className="py-3 pr-4 text-right tabular-nums">
                            <span
                              className={
                                canFulfill
                                  ? 'text-green-600 font-medium'
                                  : 'text-orange-600 font-medium'
                              }
                            >
                              {req.current_available.toLocaleString()}
                            </span>
                          </td>
                          <td className="py-3 pr-4">
                            <Badge
                              variant={canFulfill ? 'default' : 'destructive'}
                              className={canFulfill ? 'bg-green-100 text-green-800' : ''}
                            >
                              {canFulfill ? 'YES' : 'NO'}
                            </Badge>
                          </td>
                          <td className="py-3 pr-4 text-xs">{req.requested_by?.name ?? '—'}</td>
                          <td className="py-3 pr-4 text-xs text-muted-foreground">
                            {fmt(req.created_at)}
                          </td>
                          <td className="py-3">
                            <Button
                              size="sm"
                              variant="ghost"
                              onClick={() => router.visit(`/telesales/pool-approvals/${req.id}`)}
                            >
                              <Eye className="h-4 w-4" />
                            </Button>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </TelesalesLayout>
  );
}
