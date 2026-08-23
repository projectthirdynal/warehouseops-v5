import { Head, router } from '@inertiajs/react';
import { FolderOpen, Eye } from 'lucide-react';
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

interface LeadPool {
  id: number;
  pool_number: string;
  brand_name: string;
  business_region: string | null;
  province: string | null;
  approved_quantity: number;
  reserved_quantity: number;
  distributed_quantity: number;
  status: string;
  created_at: string;
  activated_at: string | null;
  completed_at: string | null;
  request: { id: number; request_number: string; requested_by: { name: string } } | null;
}

interface PaginatedPools {
  data: LeadPool[];
  total: number;
  current_page: number;
  last_page: number;
  from: number | null;
  to: number | null;
  links: Array<{ url: string | null; label: string; active: boolean }>;
}

interface StatusOption {
  value: string;
  label: string;
}

interface Props {
  pools: PaginatedPools;
  statusFilter: string;
  statusOptions: StatusOption[];
}

const statusColors: Record<string, string> = {
  READY: 'bg-blue-100 text-blue-800',
  ACTIVE: 'bg-green-100 text-green-800',
  PARTIALLY_DISTRIBUTED: 'bg-yellow-100 text-yellow-800',
  FULLY_DISTRIBUTED: 'bg-emerald-100 text-emerald-800',
  COMPLETED: 'bg-gray-100 text-gray-800',
  CANCELLED: 'bg-red-100 text-red-800',
};

export default function LeadPoolsIndex({ pools, statusFilter, statusOptions }: Props) {
  const fmt = (d: string) =>
    new Date(d).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' });

  return (
    <TelesalesLayout>
      <Head title="Lead Pools — Telesales" />
      <div className="space-y-6 p-6">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Lead Pools</h1>
          <p className="text-sm text-muted-foreground mt-1">
            Approved lead pools ready for distribution.
          </p>
        </div>

        <Select
          value={statusFilter}
          onValueChange={(v) =>
            router.get('/telesales/pools', { status: v }, { preserveState: true })
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

        <Card>
          <CardHeader>
            <CardTitle>Pools ({pools.total})</CardTitle>
          </CardHeader>
          <CardContent>
            {pools.data.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-12 text-center">
                <FolderOpen className="h-12 w-12 text-muted-foreground mb-3" />
                <p className="text-muted-foreground">No lead pools yet.</p>
              </div>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b text-left">
                      <th className="pb-3 pr-4 font-medium">Pool #</th>
                      <th className="pb-3 pr-4 font-medium">Brand</th>
                      <th className="pb-3 pr-4 font-medium">Region</th>
                      <th className="pb-3 pr-4 text-right font-medium">Reserved</th>
                      <th className="pb-3 pr-4 text-right font-medium">Distributed</th>
                      <th className="pb-3 pr-4 text-right font-medium">Remaining</th>
                      <th className="pb-3 pr-4 font-medium">Status</th>
                      <th className="pb-3 pr-4 font-medium">Created</th>
                      <th className="pb-3 font-medium"></th>
                    </tr>
                  </thead>
                  <tbody>
                    {pools.data.map((pool) => {
                      const remaining = pool.reserved_quantity - pool.distributed_quantity;
                      return (
                        <tr key={pool.id} className="border-b last:border-0 hover:bg-muted/50">
                          <td className="py-3 pr-4 font-mono text-xs">{pool.pool_number}</td>
                          <td className="py-3 pr-4 font-medium">{pool.brand_name}</td>
                          <td className="py-3 pr-4">
                            {pool.business_region || pool.province || 'All'}
                          </td>
                          <td className="py-3 pr-4 text-right tabular-nums">
                            {pool.reserved_quantity.toLocaleString()}
                          </td>
                          <td className="py-3 pr-4 text-right tabular-nums">
                            {pool.distributed_quantity.toLocaleString()}
                          </td>
                          <td className="py-3 pr-4 text-right tabular-nums font-medium">
                            {remaining.toLocaleString()}
                          </td>
                          <td className="py-3 pr-4">
                            <Badge
                              className={statusColors[pool.status] ?? 'bg-gray-100'}
                              variant="secondary"
                            >
                              {pool.status.replace(/_/g, ' ')}
                            </Badge>
                          </td>
                          <td className="py-3 pr-4 text-xs text-muted-foreground">
                            {fmt(pool.created_at)}
                          </td>
                          <td className="py-3">
                            <Button
                              size="sm"
                              variant="ghost"
                              onClick={() => router.visit(`/telesales/pools/${pool.id}`)}
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
